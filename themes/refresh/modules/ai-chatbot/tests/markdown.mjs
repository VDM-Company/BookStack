/**
 * Tests the widget's Markdown renderer, with emphasis on injection safety.
 *
 * Model output is rendered as HTML, so this is the module's main untrusted-input
 * boundary in the browser. The renderer escapes everything before introducing
 * any tag and filters link schemes; these checks pin that behaviour down.
 *
 * The pure-function region of the shipped chat.js is imported directly, so the
 * test exercises the real code rather than a transcription of it.
 */

import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const source = readFileSync(path.join(here, '../public/ai-chatbot/chat.js'), 'utf8');

const marker = '/* --------------------------------------------------------------------- icons */';
const cut = source.indexOf(marker);

if (cut === -1) {
    console.error('Could not find the icons marker in chat.js; the file layout changed.');
    process.exit(2);
}

globalThis.window = {location: {origin: 'https://wiki.test'}};

const module = `${source.slice(0, cut)}\nexport {renderMarkdown, esc, safeHref, safeImageSrc, splitFollowUps, resolveFollowUps, pickFallbackFollowUps};`;
const {renderMarkdown, esc, safeHref, safeImageSrc, splitFollowUps, resolveFollowUps, pickFallbackFollowUps} = await import(`data:text/javascript,${encodeURIComponent(module)}`);

let failures = 0;
let total = 0;

const green = text => `\x1b[32m${text}\x1b[0m`;
const red = text => `\x1b[31m${text}\x1b[0m`;
const dim = text => `\x1b[2m${text}\x1b[0m`;

function check(label, ok, detail = '') {
    total++;
    if (!ok) failures++;
    console.log(`    ${ok ? green('PASS') : red('FAIL')}  ${label}${detail ? `  ${dim(`(${detail})`)}` : ''}`);
}

const renders = (label, input, expected) => {
    const actual = renderMarkdown(input);
    check(label, actual === expected, actual === expected ? '' : `got ${JSON.stringify(actual)}`);
};

const contains = (label, input, needle) => {
    const actual = renderMarkdown(input);
    check(label, actual.includes(needle), actual.includes(needle) ? '' : `got ${JSON.stringify(actual)}`);
};

const excludes = (label, input, needle) => {
    const actual = renderMarkdown(input);
    check(label, !actual.includes(needle), !actual.includes(needle) ? '' : `got ${JSON.stringify(actual)}`);
};

const section = name => console.log(`\n  ${name}`);

console.log('\n\x1b[1mMarkdown renderer\x1b[0m');

section('Theme asset MIME');
check(
    'chat.js source has no HTML tag literals',
    !/<[a-zA-Z/!]/.test(source),
    'finfo would classify the file as text/html and BookStack would serve it as text/plain',
);

section('Injection safety');
excludes('script tag not emitted', '<script>alert(1)</script>', '<script>');
contains('script tag escaped instead', '<script>alert(1)</script>', '&lt;script&gt;');
excludes('img onerror not emitted', '<img src=x onerror=alert(1)>', '<img');
excludes('svg onload not emitted', '<svg onload=alert(1)>', '<svg');
excludes('javascript: link rejected', '[click](javascript:alert(1))', 'href');
excludes('mixed-case javascript: rejected', '[click](JaVaScRiPt:alert(1))', 'href');
excludes('data: link rejected', '[click](data:text/html;base64,PHNjcmlwdD4=)', 'href');
excludes('vbscript: link rejected', '[click](vbscript:msgbox)', 'href');
excludes('protocol-relative link rejected', '[click](//evil.test/x)', 'href');
excludes('attribute break-out escaped', '[x](https://a.test/" onmouseover="alert(1))', 'onmouseover="alert(1)"');
excludes('html in heading escaped', '# <b>bold</b>', '<b>');
excludes('html in list item escaped', '- <iframe src=x>', '<iframe');
excludes('html in table cell escaped', '| a |\n|---|\n| <svg onload=x> |', '<svg');
excludes('html in code fence escaped', '```\n<script>alert(1)</script>\n```', '<script>');
excludes('html in blockquote escaped', '> <script>x</script>', '<script>');

section('Images');
renders('markdown image', '![diagram](/uploads/images/gallery/d.png)',
    '<p><img src="/uploads/images/gallery/d.png" alt="diagram" loading="lazy"></p>');
renders('empty alt is allowed', '![](/uploads/images/gallery/d.png)',
    '<p><img src="/uploads/images/gallery/d.png" alt="" loading="lazy"></p>');
contains('same-origin wiki image becomes a path', '![d](https://wiki.test/uploads/images/gallery/x.png)',
    'src="/uploads/images/gallery/x.png"');
contains('attachment image allowed', '![file](/attachments/9?open=true)',
    'src="/attachments/9?open=true"');
excludes('javascript image rejected', '![x](javascript:alert(1))', '<img');
excludes('data image rejected', '![x](data:image/png;base64,aaaa)', '<img');
excludes('off-site image rejected', '![x](https://evil.test/uploads/images/gallery/x.png)', '<img');
excludes('path traversal rejected', '![x](/uploads/images/../etc/passwd)', '<img');
excludes('protocol-relative image rejected', '![x](//evil.test/x.png)', '<img');
excludes('onerror break-out not emitted', '![x](/uploads/images/gallery/x.png" onerror="alert(1))', 'onerror="alert(1)"');
contains('image wins over a link on the same text', '![diagram](/uploads/images/gallery/d.png)', '<img');
check('safeImageSrc allows gallery path', safeImageSrc('/uploads/images/gallery/a.png') === '/uploads/images/gallery/a.png');
check('safeImageSrc rejects javascript', safeImageSrc('javascript:x') === null);

section('Links');
contains('external link opens in a new tab', '[docs](https://example.test/a)',
    '<a href="https://example.test/a" target="_blank" rel="noopener noreferrer">docs</a>');
contains('root-relative link stays in the tab', '[page](/books/ops/page/x)', '<a href="/books/ops/page/x">page</a>');
contains('same-origin link stays in the tab', '[page](https://wiki.test/books/x)', '<a href="https://wiki.test/books/x">page</a>');
contains('fragment link allowed', '[top](#top)', '<a href="#top">top</a>');
contains('query ampersand escaped', '[q](/search?a=1&b=2)', 'href="/search?a=1&amp;b=2"');

section('Block rendering');
renders('paragraph', 'Hello world', '<p>Hello world</p>');
renders('soft-wrapped lines join', 'one\ntwo', '<p>one two</p>');
renders('blank line splits paragraphs', 'a\n\nb', '<p>a</p><p>b</p>');
renders('h1 demoted to h2', '# Title', '<h2>Title</h2>');
renders('h3 demoted to h4', '### Title', '<h4>Title</h4>');
renders('bullet list', '- a\n- b', '<ul><li>a</li><li>b</li></ul>');
renders('asterisk bullets', '* a\n* b', '<ul><li>a</li><li>b</li></ul>');
renders('numbered list', '1. a\n2. b', '<ol><li>a</li><li>b</li></ol>');
renders('blockquote', '> quoted', '<blockquote>quoted</blockquote>');
renders('horizontal rule', '---', '<hr>');
renders('fenced code', '```\nx = 1\n```', '<pre><code>x = 1</code></pre>');
renders('fenced code with a language tag', '```php\n$x = 1;\n```', '<pre><code>$x = 1;</code></pre>');
renders('inline code', 'use `foo()` here', '<p>use <code>foo()</code> here</p>');
renders('bold', 'a **b** c', '<p>a <strong>b</strong> c</p>');
renders('italic with asterisks', 'a *b* c', '<p>a <em>b</em> c</p>');
renders('italic with underscores', 'a _b_ c', '<p>a <em>b</em> c</p>');
renders('strikethrough', 'a ~~b~~ c', '<p>a <del>b</del> c</p>');
renders('table', '| a | b |\n|---|---|\n| 1 | 2 |',
    '<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>');
renders('pipe row without a delimiter is a paragraph', '| a | b |', '<p>| a | b |</p>');
renders('streaming table header is a paragraph', 'Here is a comparison:\n\n| Name | Role |',
    '<p>Here is a comparison:</p><p>| Name | Role |</p>');
renders('paragraph then a complete table', 'intro\n| a | b |\n|---|---|\n| 1 | 2 |',
    '<p>intro</p><table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>');
renders('list then paragraph', '- a\n\nafter', '<ul><li>a</li></ul><p>after</p>');
renders('heading then list', '## H\n- a', '<h3>H</h3><ul><li>a</li></ul>');
renders('order preserved across block types', 'intro\n\n- a\n\n## H', '<p>intro</p><ul><li>a</li></ul><h3>H</h3>');

section('Partial input during streaming');
renders('unterminated fence renders as code', '```\npartial cod', '<pre><code>partial cod</code></pre>');
renders('lone backtick is literal', 'a ` b', '<p>a ` b</p>');
renders('half-written bold is literal', 'a **b', '<p>a **b</p>');
renders('empty input', '', '');
renders('whitespace only', '   \n\n  ', '');
renders('half-written link is literal', '[label](http', '<p>[label](http</p>');
contains('markdown inside inline code not formatted', '`**not bold**`', '<code>**not bold**</code>');
contains('asterisks inside a fence untouched', '```\na * b * c\n```', '<code>a * b * c</code>');

section('Follow-up fence');
{
    const closed = splitFollowUps('Answer about MFA.\n\n:::followups\nHow is MFA reset?\nWhere is the VPN page?\n:::');
    check('strips a closed fence from visible text', closed.text === 'Answer about MFA.');
    check('parses two follow-up questions', closed.questions.join('|') === 'How is MFA reset?|Where is the VPN page?');

    const streaming = splitFollowUps('Answer about MFA.\n:::followups\nHow is MFA reset?');
    check('hides an unclosed fence while streaming', streaming.text === 'Answer about MFA.' && streaming.questions.length === 0);

    const plain = splitFollowUps('No fence here');
    check('leaves ordinary answers alone', plain.text === 'No fence here' && plain.questions.length === 0);

    const bullets = splitFollowUps('Done.\n:::followups\n- Reset MFA for a lost phone\n- Open the VPN setup page\n:::');
    check('strips list markers from chip lines', bullets.questions.join('|') === 'Reset MFA for a lost phone|Open the VPN setup page');

    const many = splitFollowUps('A\n:::followups\n1\n2\n3\n4\n5\n:::');
    check('caps parsed questions at four', many.questions.length === 4 && many.questions[3] === '4');

    const mid = splitFollowUps('See :::followups in passing');
    check('ignores a mid-line marker', mid.text === 'See :::followups in passing' && mid.questions.length === 0);

    const related = resolveFollowUps(['How is MFA reset?', 'Where is the VPN page?'], ['What does this wiki cover?']);
    check('prefers related chips when the fence has questions', related.join('|') === 'How is MFA reset?|Where is the VPN page?');

    const missing = resolveFollowUps([], ['What does this wiki cover?', 'What should I read first?', 'How do I find a specific policy?']);
    check('uses fallback chips when the fence is empty', missing.join('|') === 'What does this wiki cover?|What should I read first?|How do I find a specific policy?');

    const absent = resolveFollowUps(null, ['What can you help me with?', 'What does this wiki cover?']);
    check('uses fallback chips when the fence is missing', absent[0] === 'What can you help me with?' && absent.length === 2);

    const howto = 'What can you help me with?';
    const pool = [howto, 'What does this wiki cover?', 'What should I read first?', 'Summarise the page I am on', 'How do I find a specific policy?'];
    const afterHowTo = pickFallbackFollowUps(pool, howto, 3);
    check('fallback skips the last user question', !afterHowTo.includes(howto) && afterHowTo.length === 3);
    check('fallback after howto starts with wiki cover', afterHowTo[0] === 'What does this wiki cover?');

    const afterCover = pickFallbackFollowUps(pool, 'What does this wiki cover?', 3);
    check('fallback skips a matching later chip', afterCover.join('|') === 'What can you help me with?|What should I read first?|Summarise the page I am on');

    const capped = pickFallbackFollowUps(pool, '', 3);
    check('fallback shows three chips', capped.length === 3 && capped[0] === howto);
}

section('Helpers');
check('esc covers all five entities', esc(`&<>"'`) === '&amp;&lt;&gt;&quot;&#39;', esc(`&<>"'`));
check('safeHref allows https', safeHref('https://a.test') === 'https://a.test');
check('safeHref allows root-relative', safeHref('/a') === '/a');
check('safeHref allows fragments', safeHref('#x') === '#x');
check('safeHref rejects javascript:', safeHref('javascript:x') === null);
check('safeHref rejects protocol-relative', safeHref('//a.test') === null);

console.log(`\n  ${failures === 0 ? green(`All ${total} checks passed.`) : red(`${failures} of ${total} checks failed.`)}`);
process.exit(failures === 0 ? 0 : 1);
