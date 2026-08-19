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
$checks->that('forbids converting image URLs to amazonaws hosts', str_contains($prompt, 'amazonaws.com'));
$checks->that('skips editable-source images', str_contains($prompt, 'editable source'));

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
$checks->same('rejects a drawio source file', null, PageImages::sanitiseUrl('/uploads/images/gallery/flow.drawio'));
$checks->same('allows an attachment preview', '/attachments/12?open=true', PageImages::sanitiseUrl('/attachments/12?open=true'));
$checks->same('rejects a non-image attachment query', null, PageImages::sanitiseUrl('/attachments/12?open=false'));

$s3Expected = '/uploads/images/gallery/2026-08/uMYr887hjyJHjW2i-fault-triage.png';
$s3Virtual = 'https://bookstack-wiki-vdmjp-s3-bucket.s3.amazonaws.com/uploads/images/gallery/2026-08/uMYr887hjyJHjW2i-fault-triage.png';
$checks->same('rewrites virtual-hosted S3', $s3Expected, PageImages::sanitiseUrl($s3Virtual));
$checks->same(
    'rewrites regional virtual-hosted S3',
    $s3Expected,
    PageImages::sanitiseUrl('https://bookstack-wiki-vdmjp-s3-bucket.s3.ap-northeast-1.amazonaws.com/uploads/images/gallery/2026-08/uMYr887hjyJHjW2i-fault-triage.png'),
);
$checks->same(
    'rewrites path-style S3',
    $s3Expected,
    PageImages::sanitiseUrl('https://s3.amazonaws.com/bookstack-wiki-vdmjp-s3-bucket/uploads/images/gallery/2026-08/uMYr887hjyJHjW2i-fault-triage.png'),
);
$checks->same(
    'rewrites regional path-style S3',
    $s3Expected,
    PageImages::sanitiseUrl('https://s3.ap-northeast-1.amazonaws.com/bookstack-wiki-vdmjp-s3-bucket/uploads/images/gallery/2026-08/uMYr887hjyJHjW2i-fault-triage.png'),
);
$checks->same(
    'rewrites older s3-region path-style S3',
    $s3Expected,
    PageImages::sanitiseUrl('https://s3-ap-northeast-1.amazonaws.com/bookstack-wiki-vdmjp-s3-bucket/uploads/images/gallery/2026-08/uMYr887hjyJHjW2i-fault-triage.png'),
);
$checks->that('rewritten S3 URL has no amazonaws host', !str_contains((string) PageImages::sanitiseUrl($s3Virtual), 'amazonaws.com'));

$checks->same(
    'rewrites dualstack virtual-hosted S3',
    $s3Expected,
    PageImages::sanitiseUrl('https://bookstack-wiki-vdmjp-s3-bucket.s3.dualstack.ap-northeast-1.amazonaws.com/uploads/images/gallery/2026-08/uMYr887hjyJHjW2i-fault-triage.png'),
);
$checks->same(
    'rewrites s3-accelerate',
    $s3Expected,
    PageImages::sanitiseUrl('https://bookstack-wiki-vdmjp-s3-bucket.s3-accelerate.amazonaws.com/uploads/images/gallery/2026-08/uMYr887hjyJHjW2i-fault-triage.png'),
);

$fromS3Html = PageImages::extract('<img src="' . $s3Virtual . '" alt="Triage">');
$checks->same('HTML S3 src becomes a wiki path', $s3Expected, $fromS3Html[0]['url'] ?? null);

$scaledS3 = 'https://bookstack-wiki-vdmjp-s3-bucket.s3.amazonaws.com/uploads/images/gallery/2026-08/scaled-1680-/uMYr887hjyJHjW2i-fault-triage.png';
$fromScaled = PageImages::extract('<img src="' . $scaledS3 . '" alt="Triage">');
$checks->same('HTML scaled S3 thumb becomes the original wiki path', $s3Expected, $fromScaled[0]['url'] ?? null);

$galleryInsert = '[![Triage](' . $scaledS3 . ')](' . $s3Virtual . ')';
$fromNested = PageImages::extract('', $galleryInsert);
$checks->same('markdown gallery insert (thumb inside link) becomes original path', $s3Expected, $fromNested[0]['url'] ?? null);
$checks->same(
    'canonicalUploadsPath strips scaled-1680-/',
    $s3Expected,
    PageImages::canonicalUploadsPath('/uploads/images/gallery/2026-08/scaled-1680-/uMYr887hjyJHjW2i-fault-triage.png'),
);
$checks->that(
    'imageLookupPaths include the original Image.path',
    in_array($s3Expected, PageImages::imageLookupPaths('/uploads/images/gallery/2026-08/scaled-1680-/uMYr887hjyJHjW2i-fault-triage.png'), true),
);

$previousStorageUrl = config('filesystems.url');
config(['filesystems.url' => 'https://cdn.assets.test/wiki']);
$checks->same(
    'rewrites STORAGE_URL host to a wiki path',
    '/uploads/images/gallery/2026-08/x.png',
    PageImages::sanitiseUrl('https://cdn.assets.test/wiki/uploads/images/gallery/2026-08/x.png'),
);
config(['filesystems.url' => $previousStorageUrl]);

config(['filesystems.url' => 'https://bookstack-wiki-vdmjp-s3-bucket.s3.amazonaws.com']);
$checks->same(
    'rewrites STORAGE_URL when it is the S3 bucket URL',
    $s3Expected,
    PageImages::sanitiseUrl($s3Virtual),
);
$checks->that(
    'STORAGE_URL S3 host is a rewrite host',
    in_array('bookstack-wiki-vdmjp-s3-bucket.s3.amazonaws.com', PageImages::storageHosts(), true),
);
$checks->that('proxies when STORAGE_URL is an S3 host', PageImages::shouldProxyImages());
config(['filesystems.url' => $previousStorageUrl]);

$previousImagesDisk = config('filesystems.images');
config(['filesystems.images' => 's3']);
$checks->that('proxies when the image disk is s3', PageImages::shouldProxyImages());
config(['filesystems.images' => 'S3']);
$checks->that('proxies when the image disk is S3 (any case)', PageImages::shouldProxyImages());
config(['filesystems.images' => $previousImagesDisk]);
$checks->that(
    'does not proxy a local disk with no STORAGE_URL',
    !PageImages::shouldProxyImages() || strtolower((string) $previousImagesDisk) === 's3',
);

$imageController = (string) file_get_contents(dirname(__DIR__) . '/src/Http/ImageController.php');
$checks->that(
    'image proxy streams via ImageService, not local public_path',
    str_contains($imageController, 'streamImageFromStorageResponse')
        && !str_contains($imageController, 'public_path')
        && !str_contains($imageController, 'storage_path'),
);
$checks->that(
    'image proxy matches Image.path with or without thumbs/',
    str_contains($imageController, 'imageLookupPaths'),
);

$mixed = PageImages::extract(
    '<img src="/uploads/images/gallery/2026-08/seven-day-clocks.svg" alt="seven-day-clocks.svg (editable source)">'
    . '<img src="/uploads/images/gallery/2026-08/seven-day-clocks.png" alt="seven-day-clocks.png">'
    . '<img src="/uploads/images/gallery/2026-08/fault-triage.svg" alt="fault-triage.svg (editable source)">'
    . '<img src="/uploads/images/gallery/2026-08/fault-triage.png" alt="fault-triage.png">',
    '',
    8,
);
$checks->same('skips editable-source SVGs', 2, count($mixed));
$checks->same('keeps the png preview', '/uploads/images/gallery/2026-08/seven-day-clocks.png', $mixed[0]['url'] ?? null);
$checks->same('keeps the second png', '/uploads/images/gallery/2026-08/fault-triage.png', $mixed[1]['url'] ?? null);
$checks->that(
    'editable-source label is not in the extract',
    !str_contains(json_encode($mixed), 'editable source'),
);

$pair = PageImages::extract(
    '<img src="/uploads/images/gallery/flow.svg" alt="Flow">'
    . '<img src="/uploads/images/gallery/flow.png" alt="Flow">',
    '',
    8,
);
$checks->same('prefers raster over source SVG', 1, count($pair));
$checks->same('kept raster is the png', '/uploads/images/gallery/flow.png', $pair[0]['url'] ?? null);

$icon = PageImages::extract('<img src="/uploads/images/gallery/logo.svg" alt="Logo">');
$checks->same('keeps a lone SVG icon', '/uploads/images/gallery/logo.svg', $icon[0]['url'] ?? null);

$replaced = PageImages::replaceHtmlImages(
    'Before <img src="/uploads/images/drawio/2024-01/n.png" alt="Net"> after'
);
$checks->same(
    'leftover HTML images become markdown',
    'Before ![Net](/uploads/images/drawio/2024-01/n.png) after',
    $replaced,
);
$checks->same(
    'S3 HTML image becomes markdown path',
    'See ![Net](/uploads/images/drawio/2024-01/n.png)',
    PageImages::replaceHtmlImages('See <img src="https://x.s3.amazonaws.com/uploads/images/drawio/2024-01/n.png" alt="Net">'),
);
$checks->same(
    'S3 markdown image becomes a path',
    'See ![Net](/uploads/images/drawio/2024-01/n.png)',
    PageImages::rewriteEmbeddedImages('See ![Net](https://x.s3.amazonaws.com/uploads/images/drawio/2024-01/n.png)'),
);
$checks->same(
    'editable-source HTML image is dropped',
    'Before  after',
    PageImages::replaceHtmlImages('Before <img src="/uploads/images/gallery/a.svg" alt="a.svg (editable source)"> after'),
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
$checks->same('rewriteEmbeddedImages on empty text is safe', '', PageImages::rewriteEmbeddedImages(''));

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
    'stream primes proxies with more than an 8KB SSE comment',
    str_contains($streamSource, 'function prime') && str_contains($streamSource, '32768'),
);
$checks->that(
    'controller primes the stream before the agent runs',
    is_string($controller) && str_contains($controller, '$stream->prime()'),
);
$checks->that(
    'controller returns an SseResponse so headers survive middleware',
    is_string($controller) && str_contains($controller, 'new SseResponse'),
);
$checks->that(
    'SSE encoding is identity, not the invalid token none',
    str_contains($streamSource, "'Content-Encoding' => 'identity'")
        && !str_contains($streamSource, "'Content-Encoding' => 'none'")
        && !str_contains((string) $controller, "'Content-Encoding' => 'none'"),
);
$checks->that(
    'zlib compression is disabled before the body starts',
    str_contains($streamSource, "ini_set('zlib.output_compression', '0'")
        && str_contains($streamSource, 'ob_end_clean'),
);
$checks->that(
    'every SSE write flushes PHP and any leftover output buffer',
    str_contains($streamSource, 'function writeRaw')
        && str_contains($streamSource, 'ob_flush')
        && str_contains($streamSource, 'flush()'),
);

$sse = new \BookStackAiChat\Http\SseResponse(static function (): void {
});
$sse->headers->set('Cache-Control', 'no-cache, no-store, private');
$sse->headers->set('Content-Length', '999');
$sse->applyUnbufferedHeaders();
$checks->that(
    'SseResponse restores no-transform after PreventResponseCaching',
    str_contains((string) $sse->headers->get('Cache-Control'), 'no-transform'),
);
$checks->same('SseResponse Content-Encoding', 'identity', $sse->headers->get('Content-Encoding'));
$checks->same('SseResponse X-Accel-Buffering', 'no', $sse->headers->get('X-Accel-Buffering'));
$checks->same('SseResponse has no Content-Length', false, $sse->headers->has('Content-Length'));

$js = (string) file_get_contents(dirname(__DIR__) . '/public/ai-chatbot/chat.js');
$checks->that(
    'widget paints delta events as they arrive',
    str_contains($js, "name === 'delta'") && str_contains($js, 'appendText('),
);
$checks->that(
    'widget does not wait for a done event to paint text',
    !preg_match('/name\s*===\s*[\'"]done[\'"]/', $js),
);

$agentSource = (string) file_get_contents(dirname(__DIR__) . '/src/Chat/ChatAgent.php');
$checks->that(
    'agent emits a thinking status before each model turn',
    substr_count($agentSource, "emit('status', ['state' => 'thinking'])") >= 2,
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
