<?php

namespace BookStackAiChat\Anthropic;

use BookStack\Http\HttpRequestService;
use BookStackAiChat\Config;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal client for the Anthropic Messages API, streaming only.
 */
class Client
{
    protected const API_VERSION = '2023-06-01';

    public function __construct(
        protected Config $config,
        protected HttpRequestService $http,
    ) {
    }

    /**
     * Send one turn and stream the response back through $onEvent.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param callable(string, array): void    $onEvent
     *
     * @throws ApiException
     */
    public function streamMessage(
        array $messages,
        array $tools,
        string $system,
        int $timeout,
        callable $onEvent,
    ): MessageAccumulator {
        $accumulator = new MessageAccumulator();
        $streamError = null;

        $sink = new SseSink(function (string $event, array $data) use ($accumulator, $onEvent, &$streamError): void {
            if ($event === 'error') {
                $streamError = $data['error']['message'] ?? 'The AI service reported an error mid-response.';

                return;
            }

            $accumulator->handle($event, $data);
            $onEvent($event, $data);
        });

        $payload = [
            'model' => $this->config->model(),
            'max_tokens' => $this->config->maxTokens(),
            'system' => $system,
            'messages' => $this->prepareMessages($messages),
            'stream' => true,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        // Temperature is deliberately omitted: it must be 1 whenever thinking is
        // active, and newer models reject any non-default value outright.

        $client = $this->http->buildClient($timeout, [
            'connect_timeout' => 10,
        ]);

        try {
            /** @var ResponseInterface $response */
            $response = $client->request('POST', $this->config->apiBase() . '/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->config->apiKey(),
                    'anthropic-version' => self::API_VERSION,
                    'content-type' => 'application/json',
                    'accept' => 'text/event-stream',
                ],
                'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'sink' => $sink,
                'http_errors' => false,
                'on_headers' => function (ResponseInterface $response) use ($sink): void {
                    // Errors come back as a single JSON document, not an event
                    // stream, so stop SSE parsing before the body arrives.
                    if ($response->getStatusCode() !== 200) {
                        $sink->captureRawOnly();
                    }
                },
            ]);
        } catch (GuzzleException $exception) {
            throw new ApiException('Failed to reach the Anthropic API: ' . $exception->getMessage());
        }

        if ($response->getStatusCode() !== 200) {
            throw ApiException::fromResponse($response->getStatusCode(), $sink->raw());
        }

        if ($streamError !== null) {
            throw new ApiException($streamError, 500);
        }

        return $accumulator;
    }

    /**
     * PHP encodes `[]` as a JSON array. Tool `input` must be a JSON object,
     * including when the tool takes no arguments.
     *
     * @param  array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    protected function prepareMessages(array $messages): array
    {
        foreach ($messages as $i => $message) {
            if (!isset($message['content']) || !is_array($message['content'])) {
                continue;
            }

            foreach ($message['content'] as $j => $block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $input = $block['input'] ?? null;

                if ($input === null || $input === []) {
                    $messages[$i]['content'][$j]['input'] = new \stdClass();
                }
            }
        }

        return $messages;
    }
}
