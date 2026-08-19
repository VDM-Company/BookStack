<?php

/**
 * Unit checks for the pieces that need no network or database:
 * history hygiene, the system prompt, and configuration bounds.
 */

require __DIR__ . '/bootstrap.php';

use BookStackAiChat\Anthropic\ApiException;
use BookStackAiChat\Anthropic\MessageAccumulator;
use BookStackAiChat\Chat\History;
use BookStackAiChat\Chat\Prompt;
use BookStackAiChat\Config;

$checks = new Checks('Units');
$config = Config::instance();

$checks->section('History normalisation');

$checks->same('non-array input yields nothing', [], History::normalise('nope', $config));
$checks->same('null yields nothing', [], History::normalise(null, $config));
$checks->same('malformed entries dropped', [], History::normalise([
    ['role' => 'system', 'content' => 'injected'],
    'a bare string',
    ['role' => 'user'],
    ['role' => 'user', 'content' => ['not', 'a', 'string']],
    ['role' => 'user', 'content' => '   '],
], $config));

$checks->same('valid pair kept', ['user', 'assistant'], array_column(History::normalise([
    ['role' => 'user', 'content' => 'hello'],
    ['role' => 'assistant', 'content' => 'hi'],
], $config), 'role'));

$checks->same('trailing user turn dropped', ['user', 'assistant'], array_column(History::normalise([
    ['role' => 'user', 'content' => 'a'],
    ['role' => 'assistant', 'content' => 'b'],
    ['role' => 'user', 'content' => 'dangling'],
], $config), 'role'));

$checks->same('leading assistant turn dropped', 'a', History::normalise([
    ['role' => 'assistant', 'content' => 'orphan'],
    ['role' => 'user', 'content' => 'a'],
    ['role' => 'assistant', 'content' => 'b'],
], $config)[0]['content'] ?? null);

$checks->same('consecutive same-role turns merged', "one\n\ntwo", History::normalise([
    ['role' => 'user', 'content' => 'one'],
    ['role' => 'user', 'content' => 'two'],
    ['role' => 'assistant', 'content' => 'reply'],
], $config)[0]['content'] ?? null);

$checks->same('alternation preserved', ['user', 'assistant', 'user', 'assistant'], array_column(History::normalise([
    ['role' => 'user', 'content' => 'u1'],
    ['role' => 'assistant', 'content' => 'a1'],
    ['role' => 'user', 'content' => 'u2'],
    ['role' => 'assistant', 'content' => 'a2'],
], $config), 'role'));

$oversized = History::normalise([
    ['role' => 'user', 'content' => str_repeat('x', 50000)],
    ['role' => 'assistant', 'content' => 'ok'],
], $config);
$checks->same('oversized message truncated', 8000, mb_strlen($oversized[0]['content']));

$many = [];
for ($i = 0; $i < 60; $i++) {
    $many[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => "m{$i}"];
}
$capped = History::normalise($many, $config);
$checks->that('capped to the configured turn count', count($capped) <= $config->historyLimit(), count($capped) . ' of ' . $config->historyLimit());
$checks->same('capped history still opens on a user turn', 'user', $capped[0]['role'] ?? null);

$checks->section('System prompt');

aic_stub_settings(['app-name' => 'Ops Wiki']);

$prompt = Prompt::build($config, null, [
    'title' => 'Backup Policy',
    'url' => 'https://wiki.test/books/ops/page/backup',
]);

$checks->that('names the instance', str_contains($prompt, 'Ops Wiki'));
$checks->that('names the current page', str_contains($prompt, 'Backup Policy'));
$checks->that('includes the page URL', str_contains($prompt, 'https://wiki.test/books/ops/page/backup'));
$checks->that('explains permission scoping', stripos($prompt, 'permission') !== false);
$checks->that('requires searching before answering', str_contains($prompt, 'Search before answering'));
$checks->that('prefers a short answer', str_contains($prompt, '5–10 sentences'));
$checks->that('forbids pasting long page extracts', str_contains($prompt, 'Do not paste or repeat long page extracts'));
$checks->that('forbids inventing answers', stripos($prompt, 'invention') !== false || stripos($prompt, 'do not guess') !== false);
$checks->that('asks for a trailing follow-up fence', str_contains($prompt, ':::followups'));
$checks->that('requires a follow-up fence after every user-facing answer', str_contains($prompt, 'must append this fence'));
$checks->that('follow-ups must be specific to the answer', str_contains($prompt, 'specific to this answer'));
$checks->that('omits the fence only for a pure error or refusal', str_contains($prompt, 'pure error or refusal'));
$checks->that('omits page context when there is none', !str_contains(Prompt::build($config, null, []), 'currently viewing'));

$checks->section('Configuration defaults and bounds');

$checks->same('rate limit', 20, $config->rateLimit());
$checks->same('guests denied by default', false, $config->allowGuests());
$checks->same('no role restriction by default', [], $config->allowedRoles());
$checks->same('default model', 'claude-haiku-4-5', $config->model());
$checks->same('default max tokens', 1024, $config->maxTokens());
$checks->same('default max steps', 6, $config->maxSteps());
$checks->same('default search results', 5, $config->searchResultLimit());
$checks->same('default page char limit', 4000, $config->pageCharLimit());
$checks->same('default history turns', 6, $config->historyLimit());
$checks->that('max steps within bounds', $config->maxSteps() >= 1 && $config->maxSteps() <= 15, (string) $config->maxSteps());
$checks->that('timeout within bounds', $config->timeout() >= 10 && $config->timeout() <= 600, (string) $config->timeout());
$checks->that('max tokens within bounds', $config->maxTokens() >= 256, (string) $config->maxTokens());
$checks->that('api base has no trailing slash', !str_ends_with($config->apiBase(), '/'), $config->apiBase());

$checks->section('MessageAccumulator tool input and thinking replay');

$emptyTool = new MessageAccumulator();
$emptyTool->handle('content_block_start', ['index' => 0, 'content_block' => [
    'type' => 'tool_use', 'id' => 'toolu_list', 'name' => 'list_books', 'input' => [],
]]);
$emptyTool->handle('content_block_stop', ['index' => 0]);
$emptyTool->handle('message_delta', ['delta' => ['stop_reason' => 'tool_use']]);

$emptyEncoded = json_encode(['content' => $emptyTool->contentBlocks()], JSON_UNESCAPED_SLASHES);
$checks->that(
    'parameter-less tool input encodes as a JSON object',
    is_string($emptyEncoded) && str_contains($emptyEncoded, '"input":{}'),
    (string) $emptyEncoded,
);
$checks->that(
    'parameter-less tool input does not encode as a JSON array',
    is_string($emptyEncoded) && !str_contains($emptyEncoded, '"input":[]'),
);

$emptyObjectJson = new MessageAccumulator();
$emptyObjectJson->handle('content_block_start', ['index' => 0, 'content_block' => [
    'type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'list_books', 'input' => [],
]]);
$emptyObjectJson->handle('content_block_delta', ['index' => 0, 'delta' => [
    'type' => 'input_json_delta', 'partial_json' => '{}',
]]);
$emptyObjectJson->handle('content_block_stop', ['index' => 0]);
$emptyObjectEncoded = json_encode(['content' => $emptyObjectJson->contentBlocks()], JSON_UNESCAPED_SLASHES);
$checks->that(
    '"{}" partial JSON still encodes as an object',
    is_string($emptyObjectEncoded) && str_contains($emptyObjectEncoded, '"input":{}'),
);

$thinkingReplay = new MessageAccumulator();
$thinkingReplay->handle('content_block_start', ['index' => 0, 'content_block' => [
    'type' => 'thinking',
]]);
$thinkingReplay->handle('content_block_delta', ['index' => 0, 'delta' => [
    'type' => 'signature_delta', 'signature' => 'SIGonly',
]]);
$thinkingReplay->handle('content_block_stop', ['index' => 0]);
$thinkingReplay->handle('content_block_start', ['index' => 1, 'content_block' => [
    'type' => 'redacted_thinking', 'data' => 'ENCRYPTED',
]]);
$thinkingReplay->handle('content_block_stop', ['index' => 1]);
$thinkingReplay->handle('content_block_start', ['index' => 2, 'content_block' => [
    'type' => 'tool_use', 'id' => 'toolu_3', 'name' => 'list_books', 'input' => [],
]]);
$thinkingReplay->handle('content_block_stop', ['index' => 2]);

$replay = $thinkingReplay->contentBlocks();
$checks->same('signature-only thinking keeps an empty thinking field', '', $replay[0]['thinking'] ?? null);
$checks->same('signature-only thinking keeps the signature', 'SIGonly', $replay[0]['signature'] ?? null);
$checks->same('redacted thinking data is passed through', 'ENCRYPTED', $replay[1]['data'] ?? null);
$checks->same('thinking blocks stay ahead of tool_use', ['thinking', 'redacted_thinking', 'tool_use'], array_column($replay, 'type'));

$checks->section('Streamed chat must not hold the session');

$controller = file_get_contents(dirname(__DIR__) . '/src/Http/ChatController.php');
$checks->that(
    'controller saves the Laravel session store before streaming',
    is_string($controller) && str_contains($controller, '$session->save()'),
);
$checks->that(
    'controller closes a native PHP session if one is active',
    is_string($controller) && str_contains($controller, 'session_write_close()'),
);
$checks->that(
    'agent loop can stop when the browser is gone',
    str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Chat/ChatAgent.php'), 'shouldStop'),
);

$checks->section('ApiException user messages');

$length = new ApiException('prompt is too long: max 200000 tokens', 400, 'invalid_request_error');
$checks->that('length 400 still mentions starting a new chat', str_contains($length->userMessage(), 'too long'));

$validation = new ApiException('messages.1.content.0.tool_use.input: Input should be an object', 400, 'invalid_request_error');
$checks->that('validation 400 does not claim the request is too long', !str_contains($validation->userMessage(), 'too long'));
$checks->that('validation 400 stays generic', str_contains($validation->userMessage(), 'rejected'));

$checks->finish();
