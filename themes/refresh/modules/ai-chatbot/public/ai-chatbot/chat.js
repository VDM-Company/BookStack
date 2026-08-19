/**
 * AI Chatbot widget.
 *
 * Loaded as an ES module from the theme module's public folder. It is entirely
 * self-contained: it does not touch BookStack's component system, its global
 * `$components` store, or any core JS, so a BookStack upgrade cannot break it
 * through an internal API change.
 */

const STORAGE_KEY = 'bookstack-ai-chat';
const MAX_STORED = 40;

// BookStack's theme-asset MIME sniffer (WebSafeMimeSniffer) runs finfo on
// this file. A `.js` file is only remapped to text/javascript when finfo
// reports text/plain. HTML tag literals anywhere in the source make it
// report text/html, which is then served as text/plain, and browsers refuse
// to execute the module. Tags are therefore assembled at runtime.
const LT = String.fromCharCode(60);

function openTag(name, attrs) {
    return LT + name + (attrs ? ` ${attrs}` : '') + '>';
}

function markup(name, inner, attrs) {
    if (arguments.length === 1) {
        return openTag(name);
    }

    return openTag(name, attrs) + inner + openTag('/' + name);
}

/* ------------------------------------------------------------------ markdown */

const ESCAPES = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};

function esc(text) {
    return text.replace(/[&<>"']/g, char => ESCAPES[char]);
}

function safeHref(href) {
    // Anything that is not plainly http(s), root-relative or a fragment is
    // dropped, which is what keeps `javascript:` and `data:` out.
    return /^(https?:\/\/|\/(?!\/)|#)/i.test(href) ? href : null;
}

/**
 * Only same-origin BookStack gallery/draw.io paths and attachment downloads.
 * Model-invented hosts, data URIs and path traversal never become an img src.
 */
function safeImageSrc(href) {
    if (typeof href !== 'string' || href === '') return null;

    const raw = href.replace(/&amp;/g, '&');
    if (/^(javascript|data|vbscript|file):/i.test(raw) || raw.startsWith('//')) {
        return null;
    }

    let path = raw;
    if (/^https?:\/\//i.test(raw)) {
        try {
            const parsed = new URL(raw);
            if (parsed.origin !== window.location.origin) return null;
            path = parsed.pathname + parsed.search;
        } catch (error) {
            return null;
        }
    } else if (!raw.startsWith('/')) {
        return null;
    }

    if (path.includes('..') || path.includes('\\')) return null;

    const pathname = path.split('?')[0];
    const search = path.includes('?') ? path.slice(path.indexOf('?') + 1) : '';

    if (/^\/(?:[\w.-]+\/)*uploads\/images\/[A-Za-z0-9._/-]+$/.test(pathname)) {
        return pathname;
    }

    if (/^\/(?:[\w.-]+\/)*attachments\/\d+$/.test(pathname) && (search === '' || search === 'open=true')) {
        return search === 'open=true' ? `${pathname}?open=true` : pathname;
    }

    return null;
}

function inlineFormat(text) {
    let out = text.replace(/!\[([^\]\n]*)]\(([^)\s]+)\)/g, (match, alt, href) => {
        const url = safeImageSrc(href);
        if (!url) return match;
        return openTag('img', `src="${esc(url)}" alt="${alt}" loading="lazy"`);
    });

    out = out.replace(/\[([^\]\n]+)]\(([^)\s]+)\)/g, (match, label, href) => {
        const url = safeHref(href);
        if (!url) return match;
        const external = /^https?:\/\//i.test(url) && !url.startsWith(window.location.origin);
        const attrs = external ? ' target="_blank" rel="noopener noreferrer"' : '';
        return markup('a', label, `href="${url}"${attrs}`);
    });

    out = out.replace(/\*\*([^*]+)\*\*/g, markup('strong', '$1'));
    out = out.replace(/~~([^~]+)~~/g, markup('del', '$1'));
    out = out.replace(/(^|[\s(])\*([^*\n]+)\*/g, `$1${markup('em', '$2')}`);
    out = out.replace(/(^|[\s(])_([^_\n]+)_/g, `$1${markup('em', '$2')}`);

    return out;
}

function splitRow(line) {
    return line.replace(/^\||\|$/g, '').split('|').map(cell => cell.trim());
}

function isTableHeader(lines, index) {
    const line = lines[index];
    if (!line || !line.trim().startsWith('|') || index + 1 >= lines.length) {
        return false;
    }

    return /^\s*\|?[\s:|-]+\|[\s:|-]*$/.test(lines[index + 1]);
}

/**
 * A deliberately small Markdown subset, rendered from already-escaped text.
 * Everything is escaped before any tag is introduced, and hrefs are filtered,
 * so model output cannot inject markup.
 */
function renderMarkdown(source) {
    const fences = [];
    let text = source.replace(/```([^\n`]*)\n?([\s\S]*?)(?:```|$)/g, (match, lang, code) => {
        fences.push(code.replace(/\n$/, ''));
        return `\u0000F${fences.length - 1}\u0000`;
    });

    text = esc(text);

    const codes = [];
    text = text.replace(/`([^`\n]+)`/g, (match, code) => {
        codes.push(code);
        return `\u0000C${codes.length - 1}\u0000`;
    });

    const lines = text.split('\n');
    const html = [];
    let i = 0;

    const flushParagraph = buffer => {
        if (buffer.length) html.push(markup('p', inlineFormat(buffer.join(' '))));
    };

    while (i < lines.length) {
        const line = lines[i];
        const trimmed = line.trim();

        if (trimmed === '') { i++; continue; }

        const fence = trimmed.match(/^\u0000F(\d+)\u0000$/);
        if (fence) {
            html.push(markup('pre', markup('code', esc(fences[Number(fence[1])]))));
            i++;
            continue;
        }

        const heading = trimmed.match(/^(#{1,6})\s+(.+)$/);
        if (heading) {
            const level = Math.min(heading[1].length + 1, 6);
            html.push(markup('h' + level, inlineFormat(heading[2])));
            i++;
            continue;
        }

        if (/^([-*_])\1{2,}$/.test(trimmed.replace(/\s/g, ''))) {
            html.push(markup('hr'));
            i++;
            continue;
        }

        // Table: a header row followed by a |---|---| delimiter row.
        // A lone pipe line (common while a table is still streaming) is not
        // a table yet and must be consumed as a paragraph, otherwise `i`
        // never advances and the tab freezes.
        if (isTableHeader(lines, i)) {
            const head = splitRow(trimmed).map(cell => markup('th', inlineFormat(cell))).join('');
            const body = [];
            i += 2;
            while (i < lines.length && lines[i].trim().startsWith('|')) {
                body.push(markup('tr', splitRow(lines[i].trim()).map(cell => markup('td', inlineFormat(cell))).join('')));
                i++;
            }
            html.push(markup('table', markup('thead', markup('tr', head)) + markup('tbody', body.join(''))));
            continue;
        }

        if (trimmed.startsWith('&gt;')) {
            const quote = [];
            while (i < lines.length && lines[i].trim().startsWith('&gt;')) {
                quote.push(lines[i].trim().replace(/^&gt;\s?/, ''));
                i++;
            }
            html.push(markup('blockquote', inlineFormat(quote.join(' '))));
            continue;
        }

        const bullet = /^[-*+]\s+(.*)$/;
        const numbered = /^\d+[.)]\s+(.*)$/;

        if (bullet.test(trimmed) || numbered.test(trimmed)) {
            const ordered = numbered.test(trimmed);
            const pattern = ordered ? numbered : bullet;
            const items = [];

            while (i < lines.length) {
                const candidate = lines[i].trim();
                const match = candidate.match(pattern);

                if (match) {
                    items.push(match[1]);
                    i++;
                } else if (candidate !== '' && /^\s+\S/.test(lines[i]) && items.length) {
                    // Continuation line of the previous item.
                    items[items.length - 1] += ` ${candidate}`;
                    i++;
                } else {
                    break;
                }
            }

            const tag = ordered ? 'ol' : 'ul';
            html.push(markup(tag, items.map(item => markup('li', inlineFormat(item))).join('')));
            continue;
        }

        const paragraph = [];
        while (i < lines.length) {
            const candidate = lines[i].trim();
            if (candidate === '' ||
                /^\u0000F\d+\u0000$/.test(candidate) ||
                /^#{1,6}\s/.test(candidate) ||
                bullet.test(candidate) ||
                numbered.test(candidate) ||
                candidate.startsWith('&gt;') ||
                isTableHeader(lines, i)) {
                break;
            }
            paragraph.push(candidate);
            i++;
        }

        if (!paragraph.length) {
            i++;
            continue;
        }

        flushParagraph(paragraph);
    }

    return html.join('')
        .replace(/\u0000C(\d+)\u0000/g, (match, index) => markup('code', codes[Number(index)]));
}

/* ----------------------------------------------------------------- follow-ups */

const FOLLOWUPS_OPEN = ':::followups';

function parseFollowUpLines(inner) {
    const questions = [];

    String(inner).split('\n').forEach(line => {
        let trimmed = line.trim().replace(/^[-*+]\s+/, '').replace(/^\d+[.)]\s+/, '');
        if (trimmed === '' || trimmed === ':::') return;
        if (questions.length < 4) questions.push(trimmed);
    });

    return questions;
}

/**
 * Split a trailing :::followups ... ::: machine block from model output.
 * An unclosed opener at the end is treated as still arriving, so it is hidden.
 */
function splitFollowUps(source) {
    const text = typeof source === 'string' ? source : '';
    const start = text.lastIndexOf(FOLLOWUPS_OPEN);

    if (start === -1 || (start > 0 && text[start - 1] !== '\n')) {
        return {text, questions: []};
    }

    const before = text.slice(0, start).replace(/\s+$/, '');
    const after = text.slice(start + FOLLOWUPS_OPEN.length);
    const closed = after.match(/^[ \t]*\r?\n([\s\S]*?)\r?\n[ \t]*:::[ \t]*\s*$/);

    if (closed) {
        return {text: before, questions: parseFollowUpLines(closed[1])};
    }

    return {text: before, questions: []};
}

/**
 * Prefer model-supplied follow-ups. If the fence was missing or empty,
 * use the fallback list instead.
 */
function resolveFollowUps(related, fallback) {
    const relatedClean = (Array.isArray(related) ? related : [])
        .map(text => String(text).trim())
        .filter(text => text !== '')
        .slice(0, 4);

    if (relatedClean.length) return relatedClean;

    return (Array.isArray(fallback) ? fallback : [])
        .map(text => String(text).trim())
        .filter(text => text !== '')
        .slice(0, 4);
}

/**
 * Pick up to `limit` fallback chips, skipping any that match the last user
 * message so we never offer the question they just asked.
 */
function pickFallbackFollowUps(candidates, lastUser, limit = 3) {
    const last = String(lastUser || '').trim();

    return (Array.isArray(candidates) ? candidates : [])
        .map(text => String(text).trim())
        .filter(text => text !== '' && text !== last)
        .slice(0, limit);
}

/* --------------------------------------------------------------------- icons */

const SVG_NS = 'http://www.w3.org/2000/svg';

const ICONS = {
    // Generic sparkle (launcher + header). Not the Claude brand mark.
    spark: 'M12 2l1.9 5.6L19.5 9.5 13.9 11.4 12 17l-1.9-5.6L4.5 9.5l5.6-1.9L12 2zm6.5 10l.9 2.6 2.6.9-2.6.9-.9 2.6-.9-2.6-2.6-.9 2.6-.9.9-2.6zM6 15l.7 2 2 .7-2 .7L6 20.4l-.7-2-2-.7 2-.7L6 15z',
    // Official Claude asterisk logomark (Simple Icons, viewBox 0 0 24 24).
    // Footer credit only. https://github.com/simple-icons/simple-icons/blob/develop/icons/claude.svg
    claude: 'm4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z',
    close: 'M19 6.4L17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z',
    reset: 'M12 5V1L7 6l5 5V7a6 6 0 11-6 6H4a8 8 0 108-8z',
    send: 'M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a.993.993 0 00-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z',
    check: 'M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z',
};

function svgIcon(d) {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('xmlns', SVG_NS);
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '24');
    svg.setAttribute('height', '24');
    svg.setAttribute('fill', 'currentColor');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');

    const path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', d);
    path.setAttribute('fill', 'currentColor');
    svg.append(path);

    return svg;
}

function placeIcons(root) {
    root.querySelectorAll('[data-icon]').forEach(slot => {
        const d = ICONS[slot.dataset.icon];
        if (d) slot.prepend(svgIcon(d));
    });
}

/* ------------------------------------------------------------------- widget */

class Chatbot {
    constructor(root) {
        this.root = root;
        this.endpoint = root.dataset.endpoint;
        this.appName = root.dataset.appName || '';
        this.strings = this.readStrings(root.dataset.strings);
        this.messages = this.load();
        this.controller = null;
        this.open = false;
        // Bumped on reset, so an in-flight reply that is aborted cannot write
        // itself back into the conversation it was cleared from.
        this.generation = 0;

        this.build();
        this.restore();
    }

    readStrings(raw) {
        try {
            return JSON.parse(raw || '{}');
        } catch (error) {
            return {};
        }
    }

    t(key, replacements = {}) {
        let value = this.strings[key] || key;
        Object.keys(replacements).forEach(token => {
            value = value.replace(`:${token}`, replacements[token]);
        });
        return value;
    }

    /* ------------------------------------------------------------- markup */

    build() {
        this.root.dataset.open = 'false';

        this.launcher = document.createElement('button');
        this.launcher.type = 'button';
        this.launcher.className = 'aic-launcher';
        this.launcher.setAttribute('aria-expanded', 'false');
        this.launcher.setAttribute('aria-controls', 'aic-panel');
        const launcherLabel = document.createElement('span');
        launcherLabel.textContent = this.t('launcher');
        this.launcher.append(svgIcon(ICONS.spark), launcherLabel);
        this.launcher.addEventListener('click', () => this.toggle(true));

        this.panel = document.createElement('section');
        this.panel.className = 'aic-panel';
        this.panel.id = 'aic-panel';
        this.panel.setAttribute('role', 'dialog');
        this.panel.setAttribute('aria-label', this.t('title'));
        this.panel.hidden = true;
        this.panel.innerHTML =
            markup('div',
                markup('span', '', 'class="aic-mark" data-icon="spark"') +
                markup('h2', esc(this.t('title'))) +
                markup('button', '', `type="button" class="aic-icon-btn" data-act="reset" data-icon="reset" title="${esc(this.t('new_chat'))}" aria-label="${esc(this.t('new_chat'))}"`) +
                markup('button', '', `type="button" class="aic-icon-btn" data-act="close" data-icon="close" title="${esc(this.t('close'))}" aria-label="${esc(this.t('close'))}"`),
                'class="aic-head"') +
            markup('div', '', 'class="aic-log" role="log" aria-live="polite" aria-relevant="additions text"') +
            markup('form',
                markup('textarea', '', `rows="1" placeholder="${esc(this.t('placeholder'))}" aria-label="${esc(this.t('placeholder'))}"`) +
                markup('button', '', `type="submit" class="aic-send" data-icon="send" aria-label="${esc(this.t('send'))}" title="${esc(this.t('send'))}"`),
                'class="aic-composer"') +
            markup('p',
                esc(this.t('disclaimer')) +
                markup('span', esc(this.t('powered_by')), 'class="aic-credit" data-icon="claude"'),
                'class="aic-foot"');

        placeIcons(this.panel);

        this.log = this.panel.querySelector('.aic-log');
        this.form = this.panel.querySelector('.aic-composer');
        this.input = this.panel.querySelector('textarea');
        this.sendButton = this.panel.querySelector('.aic-send');

        this.panel.querySelector('[data-act="close"]').addEventListener('click', () => this.toggle(false));
        this.panel.querySelector('[data-act="reset"]').addEventListener('click', () => this.reset());

        this.form.addEventListener('submit', event => {
            event.preventDefault();
            this.submit();
        });

        this.input.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                this.submit();
            }
        });

        this.input.addEventListener('input', () => this.autosize());

        this.panel.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                this.toggle(false);
            }
        });

        this.log.addEventListener('scroll', () => {
            const distance = this.log.scrollHeight - this.log.scrollTop - this.log.clientHeight;
            this.pinned = distance < 40;
        });
        this.pinned = true;

        this.root.append(this.panel, this.launcher);
    }

    autosize() {
        this.input.style.height = 'auto';
        this.input.style.height = `${Math.min(this.input.scrollHeight, 140)}px`;
    }

    toggle(open) {
        this.open = open;
        this.panel.hidden = !open;
        this.root.dataset.open = String(open);
        this.launcher.setAttribute('aria-expanded', String(open));

        if (open) {
            this.input.focus();
            this.scroll(true);
        } else {
            this.launcher.focus();
        }
    }

    scroll(force = false) {
        if (force || this.pinned) {
            this.log.scrollTop = this.log.scrollHeight;
        }
    }

    /* ------------------------------------------------------------ history */

    load() {
        try {
            const stored = JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) || '[]');
            return Array.isArray(stored) ? stored : [];
        } catch (error) {
            return [];
        }
    }

    persist() {
        try {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(this.messages.slice(-MAX_STORED)));
        } catch (error) {
            // Storage may be full or blocked; the conversation still works in-memory.
        }
    }

    restore() {
        if (!this.messages.length) {
            this.showIntro();
            return;
        }

        let lastAssistant = null;
        let lastQuestions = [];
        let lastUser = '';
        let rewritten = false;

        this.messages.forEach(message => {
            const wrapper = this.addMessage(message.role);
            const body = this.addBody(wrapper);

            if (message.role === 'user') {
                lastUser = message.content;
                body.textContent = message.content;
                return;
            }

            const split = splitFollowUps(message.content);
            const stored = Array.isArray(message.followups) ? message.followups : [];

            if (split.text !== message.content) {
                message.content = split.text;
                rewritten = true;
            }

            body.innerHTML = renderMarkdown(message.content);

            if (message.images && message.images.length) {
                const extra = this.unusedImages(message.images, message.content);
                const gallery = extra.length ? this.buildImages(extra) : null;
                if (gallery) wrapper.append(gallery);
            }

            if (message.sources && message.sources.length) {
                wrapper.append(this.buildSources(message.sources));
            }

            lastAssistant = wrapper;

            if (!message.content.trim()) {
                lastQuestions = [];
                return;
            }

            const related = stored.length ? stored : split.questions;
            const questions = resolveFollowUps(related, this.fallbackFollowUps(lastUser));

            if (JSON.stringify(stored) !== JSON.stringify(questions)) {
                message.followups = questions;
                rewritten = true;
            }

            lastQuestions = questions;
        });

        if (rewritten) this.persist();
        if (lastAssistant) this.addFollowUps(lastAssistant, lastQuestions);
    }

    reset() {
        this.generation++;
        if (this.controller) this.controller.abort();
        this.messages = [];
        this.persist();
        this.log.textContent = '';
        this.showIntro();
        this.input.focus();
    }

    showIntro() {
        const intro = document.createElement('div');
        intro.className = 'aic-intro';
        intro.innerHTML =
            markup('h3', esc(this.t('greeting_heading'))) +
            markup('p', esc(this.t('greeting_body'))) +
            markup('div', '', 'class="aic-suggestions"');

        const suggestions = [this.t('suggestion_howto')];
        if (this.pageTitle()) suggestions.push(this.t('suggestion_summarise'));
        suggestions.push(this.t('suggestion_contents'));

        const holder = intro.querySelector('.aic-suggestions');
        suggestions.forEach(text => this.appendSuggestion(holder, text));

        this.log.append(intro);
    }

    clearIntro() {
        const intro = this.log.querySelector('.aic-intro');
        if (intro) intro.remove();
    }

    appendSuggestion(holder, text) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'aic-suggestion';
        button.textContent = text;
        button.addEventListener('click', () => {
            this.input.value = text;
            this.submit();
        });
        holder.append(button);
    }

    clearFollowUps() {
        this.log.querySelectorAll('.aic-followups').forEach(node => node.remove());
    }

    fallbackFollowUps(lastUserText) {
        const last = String(lastUserText || '').trim();
        const howto = this.t('suggestion_howto');
        const candidates = [];

        if (last !== howto) {
            candidates.push(howto);
        }

        candidates.push(this.t('suggestion_contents'), this.t('suggestion_read_first'));

        if (this.pageTitle()) {
            candidates.push(this.t('suggestion_summarise'));
        }

        candidates.push(this.t('suggestion_find_policy'));

        return pickFallbackFollowUps(candidates, last, 3);
    }

    addFollowUps(wrapper, questions) {
        this.clearFollowUps();

        if (!wrapper || wrapper.querySelector('.aic-error')) return;
        if (this.input.value.trim()) return;

        const messages = this.log.querySelectorAll('.aic-msg');
        if (wrapper !== messages[messages.length - 1]) return;

        const body = wrapper.querySelector('.aic-body');
        if (!body || !body.textContent.trim()) return;

        const labels = (Array.isArray(questions) ? questions : [])
            .map(text => String(text).trim())
            .filter(text => text !== '')
            .slice(0, 4);

        if (!labels.length) return;

        const row = document.createElement('div');
        row.className = 'aic-followups';

        const holder = document.createElement('div');
        holder.className = 'aic-suggestions';
        labels.forEach(text => this.appendSuggestion(holder, text));

        row.append(holder);
        wrapper.append(row);
        this.scroll();
    }

    /* ---------------------------------------------------------- rendering */

    addMessage(role) {
        const wrapper = document.createElement('div');
        wrapper.className = `aic-msg aic-msg-${role}`;
        this.log.append(wrapper);
        this.scroll();

        return wrapper;
    }

    /**
     * Answers are built from alternating text and status blocks, so an answer
     * that pauses to search reads as a timeline rather than having its prose
     * reflow above a status line appended later.
     */
    addBody(wrapper) {
        const body = document.createElement('div');
        body.className = 'aic-body';
        wrapper.append(body);
        this.scroll();

        return body;
    }

    addStatus(wrapper, text) {
        const status = document.createElement('div');
        status.className = 'aic-status';
        status.innerHTML = markup('span', markup('i', '') + markup('i', '') + markup('i', ''), 'class="aic-dots"');

        const label = document.createElement('span');
        label.textContent = text;
        status.append(label);

        wrapper.append(status);
        this.scroll();

        return status;
    }

    unusedImages(images, markdown) {
        const text = String(markdown || '');

        return (Array.isArray(images) ? images : []).filter(image => {
            const url = safeImageSrc(image && image.url);
            if (!url) return false;
            return !text.includes(url) && !text.includes(String(image.url || ''));
        });
    }

    buildImages(images) {
        const list = document.createElement('div');
        list.className = 'aic-images';
        list.setAttribute('aria-label', this.t('images'));

        images.forEach(image => {
            const url = safeImageSrc(image.url);
            if (!url) return;

            const figure = document.createElement('figure');
            const img = document.createElement('img');
            img.src = url;
            img.alt = image.alt || image.caption || '';
            img.loading = 'lazy';

            const pageUrl = image.page_url ? safeHref(image.page_url) : null;
            if (pageUrl) {
                const link = document.createElement('a');
                link.href = pageUrl;
                link.append(img);
                figure.append(link);
            } else {
                figure.append(img);
            }

            const caption = image.caption || image.alt || image.page_title;
            if (caption) {
                const label = document.createElement('figcaption');
                label.textContent = caption;
                figure.append(label);
            }

            list.append(figure);
        });

        return list.childElementCount ? list : null;
    }

    buildSources(sources) {
        const visible = 3;
        const container = document.createElement('div');
        container.className = 'aic-sources';

        const heading = document.createElement('h4');
        heading.textContent = this.t('sources');
        container.append(heading);

        const list = document.createElement('ul');
        const extras = [];

        sources.forEach((source, index) => {
            const item = document.createElement('li');
            const link = document.createElement('a');
            link.className = 'aic-source';
            link.href = source.url;
            link.dataset.type = source.type;

            const name = document.createElement('span');
            name.textContent = source.name;
            link.append(name);

            if (source.book) {
                const location = document.createElement('small');
                location.textContent = source.book;
                link.append(location);
            }

            item.append(link);

            if (index >= visible) {
                item.hidden = true;
                extras.push(item);
            }

            list.append(item);
        });

        container.append(list);

        if (extras.length) {
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'aic-sources-more';
            more.setAttribute('aria-expanded', 'false');
            more.textContent = this.t('sources_more', {count: extras.length});
            more.addEventListener('click', () => {
                const expanded = more.getAttribute('aria-expanded') === 'true';
                extras.forEach(item => {
                    item.hidden = expanded;
                });
                more.setAttribute('aria-expanded', String(!expanded));
                more.textContent = expanded
                    ? this.t('sources_more', {count: extras.length})
                    : this.t('sources_less');
            });
            container.append(more);
        }

        return container;
    }

    statusLabel(data) {
        if (data.state === 'thinking') return this.t('state_thinking');

        if (data.state === 'tool') {
            if (data.tool === 'search_wiki') {
                return data.detail
                    ? this.t('state_searching', {query: data.detail})
                    : this.t('state_searching_generic');
            }
            if (data.tool === 'read_page') {
                return data.detail
                    ? this.t('state_reading', {page: data.detail})
                    : this.t('state_reading_generic');
            }
            if (data.tool === 'list_books') return this.t('state_listing');
        }

        return this.t('state_working');
    }

    /* ------------------------------------------------------------ sending */

    pageTitle() {
        let title = document.title || '';
        if (this.appName && title.endsWith(` | ${this.appName}`)) {
            title = title.slice(0, -(this.appName.length + 3));
        }
        return title.trim();
    }

    submit() {
        const text = this.input.value.trim();
        if (!text || this.controller) return;

        this.input.value = '';
        this.autosize();
        this.clearIntro();
        this.clearFollowUps();

        this.messages.push({role: 'user', content: text});
        this.addBody(this.addMessage('user')).textContent = text;
        this.persist();

        this.stream(text);
    }

    setBusy(busy) {
        this.sendButton.disabled = busy;
        this.input.disabled = busy;
    }

    async stream(question) {
        const wrapper = this.addMessage('assistant');
        const assistant = {role: 'assistant', content: '', sources: [], images: []};
        const generation = this.generation;

        this.controller = new AbortController();
        this.setBusy(true);

        let status = this.addStatus(wrapper, this.t('state_working'));
        let body = null;
        let segment = '';
        let frame = null;

        const paint = () => {
            frame = null;
            if (body) body.innerHTML = renderMarkdown(splitFollowUps(segment).text);
            this.scroll();
        };

        const schedule = () => {
            if (frame === null) frame = window.requestAnimationFrame(paint);
        };

        const flush = () => {
            if (frame !== null) window.cancelAnimationFrame(frame);
            paint();
        };

        const finishStatus = () => {
            if (!status) return;
            status.classList.add('aic-status-done');
            const check = document.createElement('span');
            check.className = 'aic-check';
            check.append(svgIcon(ICONS.check));
            status.prepend(check);
            status = null;
        };

        const appendText = text => {
            finishStatus();

            if (!body) {
                body = this.addBody(wrapper);
                segment = '';
                if (assistant.content) assistant.content += '\n\n';
            }

            segment += text;
            assistant.content += text;
            schedule();
        };

        try {
            const response = await fetch(this.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                signal: this.controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    message: question,
                    history: this.messages.slice(0, -1).map(m => ({
                        role: m.role,
                        content: splitFollowUps(m.content).text,
                    })),
                    context: {title: this.pageTitle(), url: window.location.href},
                }),
            });

            if (!response.ok || !response.body) {
                throw new Error(await this.errorFrom(response));
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            for (;;) {
                const {done, value} = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, {stream: true});

                let breakIndex;
                while ((breakIndex = buffer.indexOf('\n\n')) !== -1) {
                    const block = buffer.slice(0, breakIndex);
                    buffer = buffer.slice(breakIndex + 2);

                    const [name, data] = this.parseEvent(block);
                    if (!name) continue;

                    if (name === 'delta') {
                        appendText(data.text || '');
                    } else if (name === 'status') {
                        if (status) {
                            status.lastElementChild.textContent = this.statusLabel(data);
                        } else {
                            // Close off the text written so far; the next delta
                            // starts a fresh block below this status line.
                            flush();
                            body = null;
                            status = this.addStatus(wrapper, this.statusLabel(data));
                        }
                    } else if (name === 'sources') {
                        assistant.sources = data.sources || [];
                    } else if (name === 'images') {
                        assistant.images = Array.isArray(data.images) ? data.images : [];
                    } else if (name === 'notice') {
                        this.appendNote(wrapper, 'aic-notice', data.message);
                    } else if (name === 'error') {
                        this.appendNote(wrapper, 'aic-error', data.message);
                    }
                }
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.appendNote(wrapper, 'aic-error', error.message || this.t('error_network'));
            }
        } finally {
            this.controller = null;
            this.setBusy(false);

            // Abandoned if the conversation was cleared while this was in flight.
            if (generation === this.generation) {
                flush();
                finishStatus();

                const extra = this.unusedImages(assistant.images, splitFollowUps(assistant.content).text);
                if (extra.length) {
                    const gallery = this.buildImages(extra);
                    if (gallery) wrapper.append(gallery);
                }

                if (assistant.sources.length) {
                    wrapper.append(this.buildSources(assistant.sources));
                }

                if (assistant.content.trim()) {
                    const split = splitFollowUps(assistant.content);
                    assistant.content = split.text;

                    if (assistant.content.trim()) {
                        const questions = resolveFollowUps(split.questions, this.fallbackFollowUps(question));
                        assistant.followups = questions;
                        this.messages.push(assistant);
                        this.persist();
                        this.addFollowUps(wrapper, questions);
                    }
                }

                this.input.focus();
                this.scroll();
            }
        }
    }

    parseEvent(block) {
        let name = '';
        let raw = '';

        block.split('\n').forEach(line => {
            if (line.startsWith('event:')) {
                name = line.slice(6).trim();
            } else if (line.startsWith('data:')) {
                raw += line.slice(5).replace(/^ /, '');
            }
        });

        if (!name) return [null, null];

        try {
            return [name, JSON.parse(raw)];
        } catch (error) {
            return [null, null];
        }
    }

    async errorFrom(response) {
        try {
            const data = await response.json();
            return data.message || this.t('error_generic');
        } catch (error) {
            return this.t('error_generic');
        }
    }

    appendNote(wrapper, className, message) {
        const note = document.createElement('div');
        note.className = className;
        note.textContent = message || this.t('error_generic');
        wrapper.append(note);
        this.scroll();
    }
}

const mount = document.getElementById('ai-chatbot');
if (mount && mount.dataset.endpoint) {
    new Chatbot(mount);
}
