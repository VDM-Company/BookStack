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
use BookStackAiChat\ErrorReport;
use BookStackAiChat\Knowledge\PageImages;

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
$checks->that('answers after one search and at most two reads', str_contains($prompt, 'at most 1–2'));
$checks->that('does not hunt for extra coverage', str_contains($prompt, 'Do not keep searching for extra coverage or completeness'));
$checks->that(
    'does not burn steps retrying a thin first search',
    str_contains($prompt, 'answer from what you have rather than retrying')
        && !str_contains($prompt, 'try again with different vocabulary before'),
);
$checks->that('capabilities questions skip search', str_contains($prompt, 'Skip the search for that kind of question'));
$checks->that('prefers a short answer', str_contains($prompt, '5–10 sentences'));
$checks->that('forbids pasting long page extracts', str_contains($prompt, 'Do not paste or repeat long page extracts'));
$checks->that('forbids inventing answers', stripos($prompt, 'invention') !== false || stripos($prompt, 'do not guess') !== false);
$checks->that('asks for a trailing follow-up fence', str_contains($prompt, ':::followups'));
$checks->that('requires a follow-up fence after every user-facing answer', str_contains($prompt, 'must append this fence'));
$checks->that('follow-ups must be specific to the answer', str_contains($prompt, 'specific to this answer'));
$checks->that('omits the fence only for a pure error or refusal', str_contains($prompt, 'pure error or refusal'));
$checks->that('omits page context when there is none', !str_contains(Prompt::build($config, null, []), 'currently viewing'));
$checks->that('tells the model to embed wiki images', str_contains($prompt, '![caption](url)'));
$checks->that('forbids inventing image URLs', str_contains($prompt, 'Do not invent, guess or rewrite image URLs'));

$checks->section('Page image extraction');

$html = '<p>Intro</p>'
    . '<img src="/uploads/images/gallery/2024-01/vpn.png" alt="VPN diagram">'
    . '<div drawio-diagram="9"><img src="/uploads/images/drawio/2024-01/flow.png" alt="Flow"></div>'
    . '<img src="javascript:alert(1)" alt="xss">'
    . '<img src="https://evil.test/uploads/images/gallery/x.png" alt="hotlink">'
    . '<img src="/uploads/images/../../etc/passwd" alt="traverse">';

$fromHtml = PageImages::extract($html, '', 8);
$checks->same('keeps two safe wiki images from HTML', 2, count($fromHtml));
$checks->same('first image is the gallery path', '/uploads/images/gallery/2024-01/vpn.png', $fromHtml[0]['url'] ?? null);
$checks->same('first image keeps alt text', 'VPN diagram', $fromHtml[0]['alt'] ?? null);
$checks->same('second image is the drawing', '/uploads/images/drawio/2024-01/flow.png', $fromHtml[1]['url'] ?? null);

$local = url('/uploads/images/gallery/2024-01/logo.png');
$fromLocal = PageImages::extract('<img src="' . $local . '" alt="Logo">');
$checks->same('absolute same-host URL is accepted', 1, count($fromLocal));
$checks->same('absolute same-host URL is normalised', PageImages::sanitiseUrl($local), $fromLocal[0]['url'] ?? null);

$fromMd = PageImages::extract('', 'See ![Shot](/uploads/images/gallery/2024-01/ui.png) please');
$checks->same('reads a markdown image', '/uploads/images/gallery/2024-01/ui.png', $fromMd[0]['url'] ?? null);
$checks->same('markdown alt is kept', 'Shot', $fromMd[0]['alt'] ?? null);

$capped = PageImages::extract(
    '<img src="/uploads/images/gallery/a.png" alt="a">'
    . '<img src="/uploads/images/gallery/b.png" alt="b">'
    . '<img src="/uploads/images/gallery/c.png" alt="c">'
    . '<img src="/uploads/images/gallery/d.png" alt="d">'
    . '<img src="/uploads/images/gallery/e.png" alt="e">',
    '',
    4,
);
$checks->same('caps images per page', 4, count($capped));

$duped = PageImages::extract(
    '<img src="/uploads/images/gallery/same.png" alt="html">',
    '![md](/uploads/images/gallery/same.png)',
);
$checks->same('deduplicates the same URL', 1, count($duped));

$checks->same('rejects javascript URLs', null, PageImages::sanitiseUrl('javascript:alert(1)'));
$checks->same('rejects data URLs', null, PageImages::sanitiseUrl('data:image/png;base64,aaaa'));
$checks->same('rejects protocol-relative URLs', null, PageImages::sanitiseUrl('//evil.test/x.png'));
$checks->same('rejects off-site hosts', null, PageImages::sanitiseUrl('https://evil.test/uploads/images/gallery/x.png'));
$checks->same('rejects path traversal', null, PageImages::sanitiseUrl('/uploads/images/../secrets/x.png'));
$checks->same('allows an attachment preview', '/attachments/12?open=true', PageImages::sanitiseUrl('/attachments/12?open=true'));
$checks->same('rejects a non-image attachment query', null, PageImages::sanitiseUrl('/attachments/12?open=false'));

$replaced = PageImages::replaceHtmlImages(
    'Before <img src="/uploads/images/drawio/2024-01/n.png" alt="Net"> after'
);
$checks->same(
    'leftover HTML images become markdown',
    'Before ![Net](/uploads/images/drawio/2024-01/n.png) after',
    $replaced,
);

$listed = PageImages::formatForModel([
    ['url' => '/uploads/images/gallery/a.png', 'alt' => 'A', 'page_title' => 'VPN'],
]);
$checks->that('model listing includes the markdown', str_contains($listed, '![A](/uploads/images/gallery/a.png)'));
$checks->that('model listing names the page', str_contains($listed, 'VPN'));
$checks->that('model listing forbids invented URLs', str_contains($listed, 'do not invent URLs'));
$checks->same('empty listing is blank', '', PageImages::formatForModel([]));
$checks->same('empty page extract is safe', [], PageImages::extract('', '', 4));
$checks->same('replaceHtmlImages on empty text is safe', '', PageImages::replaceHtmlImages(''));

$agent = file_get_contents(dirname(__DIR__) . '/src/Chat/ChatAgent.php');
$checks->that(
    'agent emits a structured images event',
    is_string($agent) && str_contains($agent, "emit('images'"),
);
$checks->that(
    'last agent step streams with empty tools',
    is_string($agent) && str_contains($agent, '$step < $maxSteps ? $this->knowledge->toolDefinitions() : []'),
);
$checks->that(
    'research-step-limit notice is not a hard stop',
    is_string($agent) && !str_contains($agent, 'reached its research step limit'),
);

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
    'pre-stream failures become a JSON refuse rather than an HTML 500',
    is_string($controller)
        && str_contains($controller, 'handleMessage')
        && str_contains($controller, 'ValidationException')
        && str_contains($controller, "refuse('The assistant hit an unexpected error.', 500)"),
);
$checks->that(
    'done events carry an object payload',
    is_string($controller) && str_contains($controller, "send('done', ['ok' => true])"),
);
$checks->that(
    'agent done events carry an object payload',
    str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Chat/ChatAgent.php'), "emit('done', ['ok' => true])"),
);
$fromPage = file_get_contents(dirname(__DIR__) . '/src/Knowledge/PageImages.php');
$checks->that(
    'page image extraction cannot escape into a 500',
    is_string($fromPage) && substr_count($fromPage, 'catch (\\Throwable)') >= 4,
);
$checks->that(
    'agent loop can stop when the browser is gone',
    str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Chat/ChatAgent.php'), 'shouldStop'),
);
$streamSource = (string) file_get_contents(dirname(__DIR__) . '/src/Http/EventStream.php');
$checks->that(
    'stream primes proxies with an 8KB SSE comment',
    str_contains($streamSource, 'function prime') && str_contains($streamSource, '8192'),
);
$checks->that(
    'controller primes the stream before the agent runs',
    is_string($controller) && str_contains($controller, '$stream->prime()'),
);
$checks->that(
    'SSE response asks proxies not to gzip or transform',
    is_string($controller)
        && str_contains($controller, 'no-transform')
        && str_contains($controller, "'Content-Encoding' => 'none'"),
);

$checks->section('ApiException user messages');

$length = new ApiException('prompt is too long: max 200000 tokens', 400, 'invalid_request_error');
$checks->that('length 400 still mentions starting a new chat', str_contains($length->userMessage(), 'too long'));

$validation = new ApiException('messages.1.content.0.tool_use.input: Input should be an object', 400, 'invalid_request_error');
$checks->that('validation 400 does not claim the request is too long', !str_contains($validation->userMessage(), 'too long'));
$checks->that('validation 400 stays generic', str_contains($validation->userMessage(), 'rejected'));

$checks->section('Error reporting');

$checks->that('ErrorReport is loadable', class_exists(ErrorReport::class));
$threw = false;
try {
    ErrorReport::message('unit check — do not alert', ['source' => 'unit']);
} catch (Throwable) {
    $threw = true;
}
$checks->that('message() does not throw without a DSN', !$threw);

$checks->finish();
