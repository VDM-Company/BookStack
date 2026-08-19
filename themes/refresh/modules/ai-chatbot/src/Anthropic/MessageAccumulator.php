<?php

namespace BookStackAiChat\Anthropic;

/**
 * Rebuilds a complete assistant message from a stream of Messages API events.
 *
 * The reassembled blocks are fed back verbatim as the assistant turn on the
 * next request. That matters for thinking-capable models: the API rejects a
 * tool-use continuation whose assistant turn has had its thinking blocks
 * (and their signatures) stripped.
 */
class MessageAccumulator
{
    /** @var array<int, array<string, mixed>> */
    protected array $blocks = [];

    /** @var array<int, string> */
    protected array $partialJson = [];

    protected ?string $stopReason = null;

    protected int $inputTokens = 0;
    protected int $outputTokens = 0;

    public function handle(string $event, array $data): void
    {
        match ($event) {
            'message_start' => $this->onMessageStart($data),
            'content_block_start' => $this->onBlockStart($data),
            'content_block_delta' => $this->onBlockDelta($data),
            'content_block_stop' => $this->onBlockStop($data),
            'message_delta' => $this->onMessageDelta($data),
            default => null,
        };
    }

    protected function onMessageStart(array $data): void
    {
        $usage = $data['message']['usage'] ?? [];
        $this->inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $this->outputTokens = (int) ($usage['output_tokens'] ?? 0);
    }

    protected function onBlockStart(array $data): void
    {
        $index = (int) ($data['index'] ?? 0);
        $block = $data['content_block'] ?? [];

        $type = $block['type'] ?? '';

        if ($type === 'tool_use') {
            // `input` arrives as a stream of partial JSON fragments. An empty
            // PHP array encodes as `[]`, which the API rejects; keep a JSON
            // object so parameter-less tools such as list_books replay cleanly.
            $block['input'] = new \stdClass();
            $this->partialJson[$index] = '';
        } elseif ($type === 'thinking') {
            // Signature-only thinking blocks omit thinking_delta. The field is
            // still required when the block is sent back on the next request.
            $block['thinking'] = (string) ($block['thinking'] ?? '');
        }

        $this->blocks[$index] = $block;
    }

    protected function onBlockDelta(array $data): void
    {
        $index = (int) ($data['index'] ?? 0);
        $delta = $data['delta'] ?? [];

        if (!isset($this->blocks[$index])) {
            return;
        }

        switch ($delta['type'] ?? '') {
            case 'text_delta':
                $this->blocks[$index]['text'] = ($this->blocks[$index]['text'] ?? '') . ($delta['text'] ?? '');
                break;
            case 'thinking_delta':
                $this->blocks[$index]['thinking'] = ($this->blocks[$index]['thinking'] ?? '') . ($delta['thinking'] ?? '');
                break;
            case 'signature_delta':
                $this->blocks[$index]['signature'] = ($this->blocks[$index]['signature'] ?? '') . ($delta['signature'] ?? '');
                break;
            case 'input_json_delta':
                $this->partialJson[$index] = ($this->partialJson[$index] ?? '') . ($delta['partial_json'] ?? '');
                break;
        }
    }

    protected function onBlockStop(array $data): void
    {
        $index = (int) ($data['index'] ?? 0);

        if (!isset($this->partialJson[$index])) {
            return;
        }

        $this->blocks[$index]['input'] = $this->decodeToolInput(trim($this->partialJson[$index]));

        unset($this->partialJson[$index]);
    }

    protected function onMessageDelta(array $data): void
    {
        if (isset($data['delta']['stop_reason'])) {
            $this->stopReason = $data['delta']['stop_reason'];
        }

        // Usage on message_delta is cumulative for the message.
        if (isset($data['usage']['output_tokens'])) {
            $this->outputTokens = (int) $data['usage']['output_tokens'];
        }

        if (isset($data['usage']['input_tokens'])) {
            $this->inputTokens = (int) $data['usage']['input_tokens'];
        }
    }

    /**
     * Parse streamed tool-input JSON. `json_decode('{}', true)` is `[]` in PHP,
     * and `json_encode([])` is `[]` — both of which the Messages API rejects.
     *
     * @return array<string, mixed>|\stdClass
     */
    protected function decodeToolInput(string $json): array|\stdClass
    {
        if ($json === '') {
            return new \stdClass();
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded) || $decoded === []) {
            return new \stdClass();
        }

        return $decoded;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function contentBlocks(): array
    {
        ksort($this->blocks);

        $blocks = [];

        foreach ($this->blocks as $block) {
            $type = $block['type'] ?? '';

            // An empty trailing text block would be rejected on the next request.
            if ($type === 'text' && trim($block['text'] ?? '') === '') {
                continue;
            }

            if ($type === 'thinking') {
                $block['thinking'] = (string) ($block['thinking'] ?? '');
            }

            if ($type === 'tool_use') {
                $input = $block['input'] ?? null;

                if ($input === null || $input === []) {
                    $block['input'] = new \stdClass();
                }
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toolCalls(): array
    {
        return array_values(array_filter(
            $this->contentBlocks(),
            fn(array $block): bool => ($block['type'] ?? '') === 'tool_use'
        ));
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    /**
     * @return array{input_tokens: int, output_tokens: int}
     */
    public function usage(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }
}
