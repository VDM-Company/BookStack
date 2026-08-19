<?php

namespace BookStackAiChat\Chat;

use BookStackAiChat\Anthropic\ApiException;
use BookStackAiChat\Anthropic\Client;
use BookStackAiChat\Config;
use BookStackAiChat\ErrorReport;
use BookStackAiChat\Knowledge\KnowledgeBase;

/**
 * Drives one question to completion, letting the model interleave wiki lookups
 * with its answer, and reporting progress to the caller as it goes.
 */
class ChatAgent
{
    public function __construct(
        protected Config $config,
        protected Client $client,
        protected KnowledgeBase $knowledge,
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @param callable(string, array): void                    $emit
     * @param callable(): bool|null                            $shouldStop
     */
    public function run(array $history, string $question, string $system, callable $emit, ?callable $shouldStop = null): void
    {
        $messages = $history;
        $messages[] = ['role' => 'user', 'content' => $question];

        $deadline = time() + $this->config->timeout();
        $maxSteps = $this->config->maxSteps();
        $producedText = false;
        $stop = $shouldStop ?? static fn (): bool => false;

        for ($step = 1; $step <= $maxSteps; $step++) {
            if ($stop()) {
                return;
            }

            $remaining = $deadline - time();

            if ($remaining < 5) {
                $emit('notice', ['message' => 'The assistant ran out of time while researching this question.']);
                break;
            }

            // Tools on steps 1..maxSteps-1 only. The last turn is answer-only
            // (empty tools omit the payload key) so a lookup-happy model still
            // has to write from what it already read. maxSteps of 1 means the
            // first and only turn has no tools.
            $tools = $step < $maxSteps ? $this->knowledge->toolDefinitions() : [];

            try {
                $accumulator = $this->client->streamMessage(
                    $messages,
                    $tools,
                    $system,
                    $remaining,
                    function (string $event, array $data) use ($emit, &$producedText): void {
                        $this->forwardStreamEvent($event, $data, $emit, $producedText);
                    },
                );
            } catch (ApiException $exception) {
                $this->logFailure($exception, $messages);
                $emit('error', ['message' => $exception->userMessage()]);

                return;
            }

            if ($stop()) {
                return;
            }

            if ($accumulator->stopReason() === 'max_tokens') {
                $emit('notice', ['message' => 'The reply was cut short at the configured length limit.']);
                break;
            }

            $toolCalls = $accumulator->toolCalls();

            if ($accumulator->stopReason() !== 'tool_use' || $toolCalls === [] || $tools === []) {
                break;
            }

            // The assistant turn must be replayed in full, including any
            // thinking blocks, or the API rejects the tool continuation.
            $messages[] = ['role' => 'assistant', 'content' => $accumulator->contentBlocks()];
            $messages[] = ['role' => 'user', 'content' => $this->runTools($toolCalls, $emit, $stop)];
        }

        if ($stop()) {
            return;
        }

        try {
            $sources = $this->knowledge->sources();

            if ($sources !== []) {
                $emit('sources', ['sources' => $sources]);
            }

            $images = $this->knowledge->images();

            if ($images !== []) {
                $emit('images', ['images' => $images]);
            }
        } catch (\Throwable $exception) {
            $this->logFailure($exception);
        }

        if (!$producedText) {
            $emit('notice', ['message' => 'The assistant did not produce an answer. Please try rephrasing.']);
        }

        $emit('done', ['ok' => true]);
    }

    /**
     * Translate raw API stream events into the smaller vocabulary the browser
     * understands.
     */
    protected function forwardStreamEvent(string $event, array $data, callable $emit, bool &$producedText): void
    {
        if ($event === 'content_block_start') {
            $type = $data['content_block']['type'] ?? '';

            if ($type === 'thinking' || $type === 'redacted_thinking') {
                $emit('status', ['state' => 'thinking']);
            }

            return;
        }

        if ($event !== 'content_block_delta') {
            return;
        }

        if (($data['delta']['type'] ?? '') !== 'text_delta') {
            return;
        }

        $text = $data['delta']['text'] ?? '';

        if ($text === '') {
            return;
        }

        $producedText = true;
        $emit('delta', ['text' => $text]);
    }

    /**
     * @param array<int, array<string, mixed>> $toolCalls
     * @param callable(): bool                 $stop
     *
     * @return array<int, array<string, mixed>>
     */
    protected function runTools(array $toolCalls, callable $emit, callable $stop): array
    {
        $results = [];

        foreach ($toolCalls as $call) {
            if ($stop()) {
                break;
            }

            $name = (string) ($call['name'] ?? '');
            $rawInput = $call['input'] ?? [];
            $input = match (true) {
                is_array($rawInput) => $rawInput,
                $rawInput instanceof \stdClass => get_object_vars($rawInput),
                default => [],
            };

            $emit('status', [
                'state' => 'tool',
                'tool' => $name,
                'detail' => $this->knowledge->describe($name, $input),
            ]);

            try {
                $output = $this->knowledge->execute($name, $input);
            } catch (\Throwable $exception) {
                $this->logFailure($exception);
                $output = 'That lookup failed with an internal error. Try a different approach.';
            }

            $results[] = [
                'type' => 'tool_result',
                'tool_use_id' => $call['id'] ?? '',
                'content' => $output,
            ];
        }

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    protected function logFailure(\Throwable $exception, array $messages = []): void
    {
        $context = [
            'exception' => $exception::class,
            'turns' => count($messages),
        ];

        if ($exception instanceof ApiException) {
            $context['status'] = $exception->status;
            $context['error_type'] = $exception->type;
        }

        if ($messages !== []) {
            $context['shape'] = $this->messageShape($messages);
        }

        ErrorReport::exception($exception, $context);
    }

    /**
     * Block types and sizes only — no page bodies, keys, or prompt text.
     *
     * @param  array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    protected function messageShape(array $messages): array
    {
        $shape = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = $message['content'] ?? null;

            if (is_string($content)) {
                $shape[] = ['role' => $role, 'content' => 'text', 'chars' => mb_strlen($content)];
                continue;
            }

            if (!is_array($content)) {
                $shape[] = ['role' => $role, 'content' => gettype($content)];
                continue;
            }

            $blocks = [];

            foreach ($content as $block) {
                if (!is_array($block)) {
                    $blocks[] = ['type' => gettype($block)];
                    continue;
                }

                $type = (string) ($block['type'] ?? 'unknown');
                $info = ['type' => $type];

                if ($type === 'tool_use') {
                    $info['name'] = (string) ($block['name'] ?? '');
                    $info['input'] = $this->describeInputKind($block['input'] ?? null);
                }

                if ($type === 'tool_result') {
                    $info['chars'] = is_string($block['content'] ?? null) ? mb_strlen($block['content']) : 0;
                }

                $blocks[] = $info;
            }

            $shape[] = ['role' => $role, 'blocks' => $blocks];
        }

        return $shape;
    }

    protected function describeInputKind(mixed $input): string
    {
        if ($input instanceof \stdClass) {
            return 'object';
        }

        if (is_array($input)) {
            return $input === [] ? 'empty-array' : (array_is_list($input) ? 'list' : 'map');
        }

        return gettype($input);
    }
}
