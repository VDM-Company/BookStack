<?php

/**
 * Exercises the Anthropic client against a fake endpoint.
 *
 * The load-bearing assertion is "deltas arrive incrementally": Guzzle's cURL
 * handler buffers response bodies, so the client streams by supplying a custom
 * `sink` instead of using the `stream` request option. If someone swaps that
 * back, this test is what catches it.
 *
 * Requires tests/fake-anthropic.php to be serving on 127.0.0.1:8791.
 */

require __DIR__ . '/bootstrap.php';

use BookStack\Http\HttpRequestService;
use BookStackAiChat\Anthropic\ApiException;
use BookStackAiChat\Anthropic\Client;
use BookStackAiChat\Config;

$checks = new Checks('Anthropic streaming client');

$config = Config::instance();

$checks->section('Configuration');
$checks->same('api base points at the fake server', 'http://127.0.0.1:8791', $config->apiBase());
$checks->that('api key is readable', $config->apiKey() !== '');
$checks->that('module reports configured', $config->configured());
$checks->same('default model', 'claude-haiku-4-5', $config->model());

$client = new Client($config, app()->make(HttpRequestService::class));
$searchTool = [[
    'name' => 'search_wiki',
    'description' => 'search',
    'input_schema' => ['type' => 'object', 'properties' => new stdClass()],
]];

$checks->section('First turn: text, thinking and a tool call');

$start = microtime(true);
$deltaTimes = [];
$preamble = '';

$first = $client->streamMessage(
    [['role' => 'user', 'content' => 'When do backups run?']],
    $searchTool,
    'system prompt',
    30,
    function (string $event, array $data) use (&$deltaTimes, &$preamble, $start): void {
        if ($event === 'content_block_delta' && ($data['delta']['type'] ?? '') === 'text_delta') {
            $deltaTimes[] = microtime(true) - $start;
            $preamble .= $data['delta']['text'];
        }
    },
);

$checks->same('stop reason', 'tool_use', $first->stopReason());
$checks->same('preamble text streamed', 'Let me check the wiki.', $preamble);

// The fake server sleeps between events. Buffered delivery would fire every
// callback at once, collapsing this spread to ~0ms.
$spread = count($deltaTimes) >= 2 ? end($deltaTimes) - $deltaTimes[0] : 0.0;
$checks->that(
    'deltas arrive incrementally, not buffered',
    $spread > 0.05,
    sprintf('%.0fms between first and last delta', $spread * 1000),
);

$blocks = $first->contentBlocks();
$checks->same('content block count', 3, count($blocks));
$checks->same('block order preserved', ['thinking', 'text', 'tool_use'], array_column($blocks, 'type'));
$checks->same('thinking text reassembled', 'Need to look this up.', $blocks[0]['thinking'] ?? null);
$checks->same('thinking signature preserved', 'SIGabc', $blocks[0]['signature'] ?? null);

$calls = $first->toolCalls();
$checks->same('one tool call', 1, count($calls));
$checks->same('split partial JSON reassembled', 'backup policy', $calls[0]['input']['query'] ?? null);
$checks->same('tool_use id captured', 'toolu_1', $calls[0]['id'] ?? null);
$checks->same('usage recorded', ['input_tokens' => 42, 'output_tokens' => 60], $first->usage());

$checks->section('Second turn: answer after a tool result');

$answer = '';
$sent = [];
$second = $client->streamMessage(
    [
        ['role' => 'user', 'content' => 'When do backups run?'],
        ['role' => 'assistant', 'content' => $blocks],
        ['role' => 'user', 'content' => [[
            'type' => 'tool_result',
            'tool_use_id' => 'toolu_1',
            'content' => 'Backup policy page contents',
        ]]],
    ],
    [],
    'system prompt',
    30,
    function (string $event, array $data) use (&$answer, &$sent): void {
        if ($event === 'received_request') {
            $sent = $data;
        }

        if ($event === 'content_block_delta' && ($data['delta']['type'] ?? '') === 'text_delta') {
            $answer .= $data['delta']['text'];
        }
    },
);

$checks->same('stop reason', 'end_turn', $second->stopReason());
$checks->same('answer streamed in full', 'Backups run nightly at 02:00 UTC.', $answer);

$checks->section('What actually went over the wire');

$checks->that('request captured', $sent !== []);
$checks->same('thinking block replayed to the API', 'thinking', $sent['messages'][1]['content'][0]['type'] ?? null);
$checks->same('thinking signature survived the round trip', 'SIGabc', $sent['messages'][1]['content'][0]['signature'] ?? null);
$checks->same('tool_result replayed', 'tool_result', $sent['messages'][2]['content'][0]['type'] ?? null);
$checks->that('stream flag set', ($sent['stream'] ?? false) === true);
$checks->same('system prompt sent', 'system prompt', $sent['system'] ?? null);
$checks->same('model sent', $config->model(), $sent['model'] ?? null);
$checks->that('temperature omitted (must be unset when thinking is on)', !array_key_exists('temperature', $sent));
$checks->that('tools key omitted when there are none', !array_key_exists('tools', $sent));

$checks->section('Empty tool input (list_books-style)');

$emptyAcc = $client->streamMessage(
    [['role' => 'user', 'content' => 'TRIGGER_EMPTY_TOOL']],
    $searchTool,
    'system prompt',
    30,
    fn() => null,
);

$emptyBlocks = $emptyAcc->contentBlocks();
$emptyEncoded = json_encode(['content' => $emptyBlocks], JSON_UNESCAPED_SLASHES);
$checks->same('empty-tool stop reason', 'tool_use', $emptyAcc->stopReason());
$checks->same('empty-tool block types', ['thinking', 'redacted_thinking', 'tool_use'], array_column($emptyBlocks, 'type'));
$checks->same('signature-only thinking keeps an empty thinking field', '', $emptyBlocks[0]['thinking'] ?? null);
$checks->same('signature-only thinking keeps the signature', 'SIGempty', $emptyBlocks[0]['signature'] ?? null);
$checks->same('redacted thinking data preserved', 'REDACTEDBLOB', $emptyBlocks[1]['data'] ?? null);
$checks->same('empty tool name', 'list_books', $emptyBlocks[2]['name'] ?? null);
$checks->that(
    'replay JSON uses an object for empty tool input',
    is_string($emptyEncoded) && str_contains($emptyEncoded, '"input":{}'),
);
$checks->that(
    'replay JSON does not use an array for empty tool input',
    is_string($emptyEncoded) && !str_contains($emptyEncoded, '"input":[]'),
);

$emptyWire = [];
$client->streamMessage(
    [
        ['role' => 'user', 'content' => 'TRIGGER_EMPTY_TOOL'],
        ['role' => 'assistant', 'content' => $emptyBlocks],
        ['role' => 'user', 'content' => [[
            'type' => 'tool_result',
            'tool_use_id' => $emptyBlocks[2]['id'] ?? '',
            'content' => 'Books in this wiki: Demo',
        ]]],
    ],
    [],
    'system prompt',
    30,
    function (string $event, array $data) use (&$emptyWire): void {
        if ($event === 'received_request') {
            $emptyWire = $data;
        }
    },
);

$checks->that('continuation sent empty input as a JSON object', ($emptyWire['_raw_empty_object_inputs'] ?? 0) >= 1);
$checks->same('continuation did not send empty input as a JSON array', 0, $emptyWire['_raw_empty_array_inputs'] ?? null);

$sanitisedWire = [];
$client->streamMessage(
    [
        ['role' => 'user', 'content' => 'TRIGGER_EMPTY_TOOL follow-up'],
        ['role' => 'assistant', 'content' => [[
            'type' => 'tool_use',
            'id' => 'toolu_sanitise',
            'name' => 'list_books',
            'input' => [],
        ]]],
        ['role' => 'user', 'content' => [[
            'type' => 'tool_result',
            'tool_use_id' => 'toolu_sanitise',
            'content' => 'ok',
        ]]],
    ],
    [],
    'system prompt',
    30,
    function (string $event, array $data) use (&$sanitisedWire): void {
        if ($event === 'received_request') {
            $sanitisedWire = $data;
        }
    },
);

$checks->that('client rewrites a PHP [] tool input to a JSON object', ($sanitisedWire['_raw_empty_object_inputs'] ?? 0) >= 1);
$checks->same('client does not leave a PHP [] tool input as a JSON array', 0, $sanitisedWire['_raw_empty_array_inputs'] ?? null);

$checks->section('Error handling');

try {
    $client->streamMessage([['role' => 'user', 'content' => 'TRIGGER_AUTH_FAILURE']], [], 'sys', 10, fn() => null);
    $checks->that('HTTP error raises ApiException', false, 'no exception thrown');
} catch (ApiException $exception) {
    $checks->same('status captured', 401, $exception->status);
    $checks->that('API message parsed', str_contains($exception->getMessage(), 'invalid x-api-key'));
    $checks->same('user-facing message stays generic', "The AI service rejected this instance's API key.", $exception->userMessage());
}

$checks->finish();
