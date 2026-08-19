<?php

/**
 * Fake Anthropic Messages endpoint used to exercise the streaming client.
 *
 * Behaviour is derived from the request body rather than a counter, so stray
 * connections from port scanners cannot desynchronise the test.
 */

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (!is_array($body) || !isset($body['messages'])) {
    http_response_code(400);
    exit;
}

// json_decode(..., true) collapses `{}` into `[]`, so the test inspects the
// raw body to know whether empty tool input went over the wire as an object.
$body['_raw_empty_object_inputs'] = substr_count((string) $rawBody, '"input":{}');
$body['_raw_empty_array_inputs'] = substr_count((string) $rawBody, '"input":[]');

$flat = json_encode($body['messages']);
$wantsFailure = str_contains($flat, 'TRIGGER_AUTH_FAILURE');
$wantsEmptyTool = str_contains($flat, 'TRIGGER_EMPTY_TOOL');
$hasToolResult = str_contains($flat, '"tool_result"');

if ($wantsFailure) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);
    exit;
}

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

function emit(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    flush();
    usleep(120000);
}

// Echo the request back through the stream so the test can assert on what was
// actually sent without depending on a shared temp file.
emit('received_request', $body);

emit('message_start', ['type' => 'message_start', 'message' => [
    'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'content' => [],
    'model' => 'claude-sonnet-5', 'stop_reason' => null,
    'usage' => ['input_tokens' => 42, 'output_tokens' => 1],
]]);

if ($wantsEmptyTool && !$hasToolResult) {
    // Mirrors Claude 5 adaptive thinking + a parameter-less tool call.
    emit('content_block_start', ['index' => 0, 'content_block' => ['type' => 'thinking']]);
    emit('content_block_delta', ['index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'SIGempty']]);
    emit('content_block_stop', ['index' => 0]);

    emit('content_block_start', ['index' => 1, 'content_block' => ['type' => 'redacted_thinking', 'data' => 'REDACTEDBLOB']]);
    emit('content_block_stop', ['index' => 1]);

    emit('content_block_start', ['index' => 2, 'content_block' => [
        'type' => 'tool_use', 'id' => 'toolu_empty', 'name' => 'list_books', 'input' => [],
    ]]);
    emit('content_block_stop', ['index' => 2]);

    emit('message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 12]]);
    emit('message_stop', []);
    exit;
}

if (!$hasToolResult) {
    // A thinking block, then preamble text, then a tool call whose JSON input
    // is split across several deltas.
    emit('content_block_start', ['index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']]);
    emit('content_block_delta', ['index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Need to look this up.']]);
    emit('content_block_delta', ['index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'SIGabc']]);
    emit('content_block_stop', ['index' => 0]);

    emit('content_block_start', ['index' => 1, 'content_block' => ['type' => 'text', 'text' => '']]);
    emit('content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Let me check ']]);
    emit('content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'the wiki.']]);
    emit('content_block_stop', ['index' => 1]);

    emit('content_block_start', ['index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search_wiki', 'input' => []]]);
    emit('content_block_delta', ['index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query": "back']]);
    emit('content_block_delta', ['index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'up policy"}']]);
    emit('content_block_stop', ['index' => 2]);

    emit('message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 60]]);
    emit('message_stop', []);
    exit;
}

emit('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
foreach (['Backups run ', 'nightly at ', '02:00 UTC.'] as $chunk) {
    emit('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $chunk]]);
}
emit('content_block_stop', ['index' => 0]);
emit('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 25]]);
emit('message_stop', []);
