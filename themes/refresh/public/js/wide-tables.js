/**
 * "refresh" theme — wide tables in page content.
 *
 * BookStack constrains every content table with `table-layout: fixed` and
 * `max-width: 100%` (resources/sass/_content.scss:49) inside an 840px content
 * column, and breaks cell text at any character (resources/sass/_tables.scss:13).
 * A table with more columns than that column has room for is therefore never
 * "too wide" on screen — it is compressed into unreadable slivers, a character
 * or two per line, with no way to see it any other way.
 *
 * This wraps content tables in a scroll container. Where a table genuinely
 * needs more room than the column gives it, the clamps come off, the table
 * takes its natural width inside that container, and a full-screen view is
 * offered with a sticky header row and an optional frozen first column.
 *
 * Only tables that MEASURE wider than the space available are touched. A table
 * that fits keeps core's rendering exactly: the wrapper is inert until the
 * measurement says otherwise, so narrow tables look identical to stock.
 *
 * A third script rather than an addition to theme.js or lightbox.js, for the
 * same reason those two are separate: each can be removed without the others.
 * If this file fails to load, every table renders as stock BookStack.
 */
(function () {
    'use strict';

    /* Reader views only — page display, page revisions, and a page set as the
       custom homepage all render content into `.page-content`. */
    var CONTENT_SELECTOR = '.page-content';

    /* The WYSIWYG editor's editable root ALSO carries the page-content class
       (resources/js/wysiwyg/index.ts:56 sets editorClass, ui/index.ts:9 puts it
       on the contenteditable area). Wrapping a table in there would inject
       theme markup into the HTML the author is about to save, so the editor is
       excluded explicitly. The markdown preview is a separate about:blank
       iframe, so this document's script never reaches it. */
    var EDITOR_SELECTOR = '[contenteditable], .editor-content-area, .markdown-editor-display';

    /* Sub-pixel rounding lets a table that exactly fits measure a fraction
       wider than its container. Two pixels is below anything a reader could
       scroll, so it is treated as "fits". */
    var TOLERANCE = 2;

    /* English, like lightbox.js. The theme carries no translations; see the
       README if these need changing for another locale. */
    var LABELS = {
        region: 'Table — scrollable sideways',
        expand: 'Full screen',
        expandTitle: 'Open this table full screen',
        dialog: 'Table viewer',
        close: 'Close',
        freeze: 'Freeze first column',
        untitled: 'Table'
    };

    var ICON_EXPAND = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" '
        + 'focusable="false"><path d="M4 4h6v2H6v4H4V4zm10 0h6v6h-2V6h-4V4zM4 14h2v4h4v2H4v-6z'
        + 'm14 0h2v6h-6v-2h4v-4z"/></svg>';

    /* One record per wrapped table. */
    var entries = [];

    var observer = null;
    var pending = false;

    /* Full-screen overlay, built on first use. */
    var fs = null;
    var fsState = {
        open: false,
        entry: null,
        frozen: false,
        prevOverflow: '',
        prevPadding: ''
    };

    function warn(err) {
        if (window.console && window.console.warn) {
            window.console.warn('refresh wide-tables:', err);
        }
    }

    function guard(handler) {
        return function (event) {
            try {
                handler(event);
            } catch (err) {
                warn(err);
            }
        };
    }

    function element(tag, className, attributes) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        Object.keys(attributes || {}).forEach(function (key) {
            node.setAttribute(key, attributes[key]);
        });
        return node;
    }

    function empty(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    /* ------------------------------------------------------------------ *
     * Wrapping
     * ------------------------------------------------------------------ */

    /**
     * Should this table be wrapped at all?
     *
     * Floated tables are left alone: `table.align-left` / `.align-right` are
     * floated by core (resources/sass/_content.scss:18,25) so surrounding text
     * flows around them. A float on the table no longer reaches the page once
     * the table sits inside a block wrapper, so wrapping one would silently
     * undo the author's layout. Such tables are deliberately small anyway.
     */
    function isEligible(table) {
        if (table.closest(EDITOR_SELECTOR) || table.isContentEditable) {
            return false;
        }
        if (table.classList.contains('align-left') || table.classList.contains('align-right')) {
            return false;
        }
        // A table nested in another table's cell scrolls with its parent.
        if (table.parentElement && table.parentElement.closest('table')) {
            return false;
        }
        return !table.closest('.rt-scroll');
    }

    function wrapTable(table) {
        var wrap = element('div', 'rt-wrap');
        var scroll = element('div', 'rt-scroll');
        var button = element('button', 'rt-expand', {
            type: 'button',
            title: LABELS.expandTitle,
            'aria-haspopup': 'dialog'
        });
        button.innerHTML = ICON_EXPAND + '<span>' + LABELS.expand + '</span>';

        table.parentNode.insertBefore(wrap, table);
        scroll.appendChild(table);
        wrap.appendChild(scroll);
        wrap.appendChild(button);

        var entry = {
            table: table,
            wrap: wrap,
            scroll: scroll,
            button: button,
            wide: false,
            /* Width the current wide/narrow decision was made at. Guards the
               resize observer against re-measuring its own writes. */
            lastWidth: -1
        };

        scroll.addEventListener('scroll', guard(function () {
            updateEdges(entry);
        }), {passive: true});

        button.addEventListener('click', guard(function () {
            openFullscreen(entry);
        }));

        entries.push(entry);
        return entry;
    }

    /* ------------------------------------------------------------------ *
     * Measurement
     * ------------------------------------------------------------------ */

    /**
     * Decide, for every wrapped table, whether it needs more room than it has.
     *
     * Batched deliberately. `rt-wide` is applied to all of them first (one
     * write), every scroll box is then measured (one layout flush), and only
     * then is the class taken back off the ones that fit (one more write).
     * Doing it table-by-table would force a separate layout per table, and
     * because no paint happens between the phases of a single frame the tables
     * that end up narrow never flash.
     */
    function evaluateAll() {
        var measurable = entries.filter(function (entry) {
            // A content area inside a hidden tab measures zero, which would
            // read as "overflowing" for every table in it.
            return entry.scroll.clientWidth > 0;
        });
        if (!measurable.length) {
            return;
        }

        measurable.forEach(function (entry) {
            entry.table.classList.add('rt-wide');
        });

        var results = measurable.map(function (entry) {
            return {
                wide: entry.scroll.scrollWidth - entry.scroll.clientWidth > TOLERANCE,
                width: entry.scroll.clientWidth
            };
        });

        measurable.forEach(function (entry, i) {
            entry.lastWidth = results[i].width;
            setWide(entry, results[i].wide);
        });
    }

    function setWide(entry, wide) {
        entry.wide = wide;
        entry.table.classList.toggle('rt-wide', wide);
        entry.wrap.classList.toggle('rt-overflow', wide);

        if (wide) {
            /* A scrollable region has to be reachable and named for anyone not
               using a mouse. Only added when the region actually scrolls, so
               a page of ordinary tables gains no tab stops. */
            entry.scroll.setAttribute('tabindex', '0');
            entry.scroll.setAttribute('role', 'region');
            entry.scroll.setAttribute('aria-label', regionLabel(entry));
        } else {
            entry.scroll.removeAttribute('tabindex');
            entry.scroll.removeAttribute('role');
            entry.scroll.removeAttribute('aria-label');
            entry.scroll.scrollLeft = 0;
        }

        updateEdges(entry);
    }

    function regionLabel(entry) {
        var title = titleFor(entry);
        return title === LABELS.untitled ? LABELS.region : title + ' — ' + LABELS.region;
    }

    /**
     * Which edges have content beyond them, so the fades can say so.
     *
     * scrollLeft runs 0 -> negative in a right-to-left document in every
     * current engine, hence the absolute value.
     */
    function updateEdges(entry) {
        var scroll = entry.scroll;
        var max = scroll.scrollWidth - scroll.clientWidth;
        var pos = Math.abs(scroll.scrollLeft);
        entry.wrap.classList.toggle('rt-more-start', entry.wide && pos > 1);
        entry.wrap.classList.toggle('rt-more-end', entry.wide && pos < max - 1);
    }

    function schedule() {
        if (pending) {
            return;
        }
        pending = true;
        window.requestAnimationFrame(guard(function () {
            pending = false;
            evaluateAll();
        }));
    }

    /** Re-measure even where the container width has not moved. */
    function forceSchedule() {
        entries.forEach(function (entry) {
            entry.lastWidth = -1;
        });
        schedule();
    }

    /**
     * Only re-measure when the space available has actually changed.
     *
     * Without this the module would feed itself: measuring means applying
     * `rt-wide`, which resizes the table, which the observer reports as a
     * change, which triggers another measurement. The scroll box is a plain
     * block whose width comes from the content column and never from its
     * contents, so its clientWidth is a signal our own writes cannot move.
     */
    function onObserved(records) {
        for (var i = 0; i < records.length; i++) {
            var entry = entryForScroll(records[i].target);
            if (entry && entry.scroll.clientWidth !== entry.lastWidth) {
                schedule();
                return;
            }
        }
    }

    function entryForScroll(node) {
        for (var i = 0; i < entries.length; i++) {
            if (entries[i].scroll === node) {
                return entries[i];
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ *
     * Full screen
     * ------------------------------------------------------------------ */

    function titleFor(entry) {
        var caption = entry.table.querySelector('caption');
        if (caption && caption.textContent.trim()) {
            return caption.textContent.trim();
        }
        var node = entry.wrap.previousElementSibling;
        while (node) {
            if (/^H[1-6]$/.test(node.tagName)) {
                var text = node.textContent.trim();
                if (text) {
                    return text;
                }
            }
            node = node.previousElementSibling;
        }
        return LABELS.untitled;
    }

    function buildFullscreen() {
        var root = element('div', 'rt-fs', {
            role: 'dialog',
            'aria-modal': 'true',
            'aria-label': LABELS.dialog
        });
        root.hidden = true;

        var bar = element('div', 'rt-fs-bar');
        var title = element('h2', 'rt-fs-title');

        var freeze = element('button', 'rt-fs-btn rt-fs-freeze', {
            type: 'button',
            'aria-pressed': 'false'
        });
        freeze.textContent = LABELS.freeze;

        var close = element('button', 'rt-fs-btn rt-fs-close', {
            type: 'button',
            'aria-label': LABELS.close
        });
        close.textContent = '×';

        bar.appendChild(title);
        bar.appendChild(freeze);
        bar.appendChild(close);

        /* `page-content` as well as our own class, so the clone inherits the
           content typography it was authored against and so images inside it
           still open in the theme's lightbox, which only listens inside
           `.page-content`. The stylesheet undoes that class's 840px cap and
           its table clamps for this one context. */
        var body = element('div', 'rt-fs-body page-content', {tabindex: '0'});

        root.appendChild(bar);
        root.appendChild(body);
        document.body.appendChild(root);

        fs = {root: root, bar: bar, title: title, freeze: freeze, close: close, body: body};

        close.addEventListener('click', guard(closeFullscreen));
        freeze.addEventListener('click', guard(toggleFreeze));
    }

    /**
     * Show a copy of the table, not the table itself.
     *
     * Moving the original out of the document would leave the page missing a
     * table for anything that reads it while the overlay is open — printing
     * most obviously. The copy is display-only, so its ids are stripped: the
     * originals are BookStack's `bkmrk-*` section anchors, which the pointer,
     * page includes and comment references all resolve by id, and a duplicate
     * would make `getElementById` ambiguous.
     */
    function openFullscreen(entry) {
        if (fsState.open) {
            return;
        }
        if (!fs) {
            buildFullscreen();
        }

        var clone = entry.table.cloneNode(true);
        clone.classList.remove('rt-wide');
        stripIds(clone);

        empty(fs.body);
        fs.body.appendChild(clone);
        fs.title.textContent = titleFor(entry);
        applyFreeze();

        fsState.entry = entry;
        fsState.open = true;
        fs.root.hidden = false;
        window.requestAnimationFrame(function () {
            fs.root.classList.add('rt-fs-open');
        });
        lockScroll();
        fs.close.focus();
    }

    function stripIds(node) {
        node.removeAttribute('id');
        var withIds = node.querySelectorAll('[id]');
        for (var i = 0; i < withIds.length; i++) {
            withIds[i].removeAttribute('id');
        }
    }

    function closeFullscreen() {
        if (!fsState.open) {
            return;
        }
        unlockScroll();
        fsState.open = false;
        fs.root.classList.remove('rt-fs-open');
        fs.root.hidden = true;
        empty(fs.body);
        fs.body.scrollTop = 0;
        fs.body.scrollLeft = 0;

        var entry = fsState.entry;
        fsState.entry = null;
        if (entry && entry.button) {
            entry.button.focus();
        }
    }

    function toggleFreeze() {
        fsState.frozen = !fsState.frozen;
        applyFreeze();
    }

    /* The state class on the root is `rt-fs-frozen`, distinct from the
       button's own `rt-fs-freeze` — one selector matching both would be a
       trap for anything later reaching for either by class. */
    function applyFreeze() {
        fs.root.classList.toggle('rt-fs-frozen', fsState.frozen);
        fs.freeze.setAttribute('aria-pressed', fsState.frozen ? 'true' : 'false');
    }

    /**
     * Freeze the page behind the overlay without losing the reader's place.
     *
     * html, not body — see the long explanation on lockScroll() in lightbox.js;
     * core gives body `height: 100%` inside an html that owns the scrollbar, so
     * hiding body's overflow throws the reader back to the top of the page.
     * Both modules save and restore the previous value, so a lightbox opened
     * from inside this overlay nests correctly.
     */
    function lockScroll() {
        var root = document.documentElement;
        var gap = window.innerWidth - root.clientWidth;
        fsState.prevOverflow = root.style.overflow;
        fsState.prevPadding = root.style.paddingRight;
        root.style.overflow = 'hidden';
        if (gap > 0) {
            var current = parseInt(window.getComputedStyle(root).paddingRight, 10) || 0;
            root.style.paddingRight = (current + gap) + 'px';
        }
    }

    function unlockScroll() {
        document.documentElement.style.overflow = fsState.prevOverflow;
        document.documentElement.style.paddingRight = fsState.prevPadding;
    }

    function onKeyDown(event) {
        if (!fsState.open) {
            return;
        }
        /* The lightbox can be opened on top of this overlay, from an image in
           a cell. It closes on Escape too, and both listen on the document, so
           one press would otherwise dismiss both layers at once. Absent or
           closed, this finds nothing and changes nothing. */
        if (document.querySelector('.rl-backdrop.rl-open')) {
            return;
        }
        if (event.key === 'Escape') {
            closeFullscreen();
            event.preventDefault();
        } else if (event.key === 'Tab') {
            trapFocus(event);
        }
    }

    /** Keep Tab inside the dialog while it is open. */
    function trapFocus(event) {
        var focusable = [fs.freeze, fs.close, fs.body];
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        var active = document.activeElement;
        var inside = fs.root.contains(active);
        if (event.shiftKey && (active === first || !inside)) {
            last.focus();
            event.preventDefault();
        } else if (!event.shiftKey && (active === last || !inside)) {
            first.focus();
            event.preventDefault();
        }
    }

    /* ------------------------------------------------------------------ *
     * The WYSIWYG editor
     * ------------------------------------------------------------------ */

    /**
     * The same crushing happens while authoring, and nothing above reaches it.
     *
     * TinyMCE runs in an iframe whose only stylesheets are core's — config.js
     * sets `content_css` to dist/styles.css and gives the editor body the
     * `page-content` class — so the clamps apply in there but the theme's
     * answer to them does not, and this script does not run in that document
     * at all. Core emits `editor-tinymce::setup` as a bubbling public event
     * carrying the editor instance, which is the supported way in.
     *
     * Unlike the reader, nothing here is wrapped. A wrapper inside
     * contenteditable would be theme markup in the HTML the author is about to
     * save, and would put a block boundary between the caret and the table.
     * The only mutation is an attribute, and that attribute is filtered out of
     * the editor's output and back off anything it parses.
     *
     * Sideways scrolling therefore comes from the editor's own body, which
     * core already gives `overflow-x: auto`. That scrolls the whole document
     * rather than the one table — the price of leaving the editable tree
     * alone, and cheap next to the risk of not doing so.
     */

    var EDITOR_MARK = 'data-refresh-wide';
    var EDITOR_STYLE_ID = 'refresh-wide-tables';

    /**
     * Mirrors the "unclamped table" rules in src/_tables.scss and has to stay
     * in step with them; the two cannot share a stylesheet because the editor
     * iframe never loads the theme's. Both selectors are one step more
     * specific than the core rule they answer, so neither needs !important.
     */
    var EDITOR_CSS = [
        '.page-content table[' + EDITOR_MARK + '] {',
        '  table-layout: auto;',
        '  max-width: none;',
        '  hyphens: manual;',
        '}',
        '.page-content table[' + EDITOR_MARK + '] > caption {',
        '  text-align: start;',
        '}',
        '.page-content table[' + EDITOR_MARK + '] th,',
        '.page-content table[' + EDITOR_MARK + '] td {',
        '  word-break: normal;',
        '  overflow-wrap: break-word;',
        '}'
    ].join('\n');

    function editorStyles(doc) {
        if (doc.getElementById(EDITOR_STYLE_ID)) {
            return;
        }
        var style = doc.createElement('style');
        style.id = EDITOR_STYLE_ID;
        style.textContent = EDITOR_CSS;
        doc.head.appendChild(style);
    }

    /**
     * The width a table has to work with, which is the content box of whatever
     * contains it — the editor body is padded, so its clientWidth is not it.
     */
    function availableWidth(node) {
        var styles = node.ownerDocument.defaultView.getComputedStyle(node);
        return node.clientWidth
            - (parseFloat(styles.paddingLeft) || 0)
            - (parseFloat(styles.paddingRight) || 0);
    }

    /**
     * Same measure-then-decide as the reader, so a table looks the same being
     * edited as it will once saved. Marking every candidate before measuring
     * keeps it to one layout flush however many tables the page has.
     */
    function evaluateEditor(body) {
        var candidates = [];
        var tables = body.querySelectorAll('table');
        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];
            // Floated and nested tables are skipped for the same reasons as in
            // the reader; see isEligible().
            if (table.classList.contains('align-left')
                    || table.classList.contains('align-right')
                    || (table.parentElement && table.parentElement.closest('table'))) {
                table.removeAttribute(EDITOR_MARK);
                continue;
            }
            candidates.push(table);
            table.setAttribute(EDITOR_MARK, '');
        }
        if (!candidates.length) {
            return;
        }

        var results = candidates.map(function (table) {
            var room = availableWidth(table.parentElement || body);
            return room > 0 && table.getBoundingClientRect().width - room > TOLERANCE;
        });

        candidates.forEach(function (table, i) {
            if (!results[i]) {
                table.removeAttribute(EDITOR_MARK);
            }
        });
    }

    /**
     * Keep the marker out of everything the editor hands back — saving, draft
     * autosave and the changelog preview all serialise through here — and off
     * anything it is given, so a marker that ever did reach stored content is
     * cleaned up on the next load rather than compounding. Core filters its
     * own stray markup the same way, in wysiwyg-tinymce/filters.js.
     */
    function stripMark(nodes) {
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].attr(EDITOR_MARK, null);
        }
    }

    function setupEditor(editor) {
        /* Inside PreInit, not here: the serializer and parser do not exist
           until then, and reaching for them during setup would throw. Core
           registers its own filters from the same event for the same reason
           (wysiwyg-tinymce/config.js). */
        editor.on('PreInit', guard(function () {
            editor.serializer.addAttributeFilter(EDITOR_MARK, stripMark);
            editor.parser.addAttributeFilter(EDITOR_MARK, stripMark);
        }));

        /**
         * Coalesced on a timer rather than an animation frame.
         *
         * The editor's events fire whether or not the tab is being rendered —
         * TableModified arrives for every column inserted, visible or not —
         * but requestAnimationFrame does not run in a background tab at all,
         * so coalescing on a frame would swallow the work and leave the table
         * measured against the shape it used to have. A timer runs either way,
         * and still collapses a burst of edits into one pass.
         */
        var timer = null;
        var run = function () {
            if (timer !== null) {
                return;
            }
            timer = window.setTimeout(guard(function () {
                timer = null;
                var body = editor.getBody();
                if (body) {
                    evaluateEditor(body);
                }
            }), 50);
        };

        editor.on('init', guard(function () {
            editorStyles(editor.getDoc());
            // Synchronous, for the same reason the reader's first pass is:
            // an editor opened in a background tab runs no animation frames.
            evaluateEditor(editor.getBody());
        }));

        /* SetContent covers loading and pasting; Undo and Redo restore an
           earlier shape of the table; TableModified is emitted by core's table
           plugin whenever a column is added, removed or resized; ResizeEditor
           covers the pane changing width. Each can change the answer. */
        editor.on('SetContent Undo Redo TableModified ResizeEditor', guard(run));
    }

    /* ------------------------------------------------------------------ *
     * Start-up
     * ------------------------------------------------------------------ */

    function init() {
        // Exports render through exports.parts.custom-head and never load this
        // file, but a body class is a cheap belt-and-braces check.
        if (document.body.classList.contains('export')) {
            return;
        }

        var containers = document.querySelectorAll(CONTENT_SELECTOR);
        for (var i = 0; i < containers.length; i++) {
            if (containers[i].closest(EDITOR_SELECTOR)) {
                continue;
            }
            var tables = containers[i].querySelectorAll('table');
            for (var t = 0; t < tables.length; t++) {
                if (isEligible(tables[t])) {
                    wrapTable(tables[t]);
                }
            }
        }

        if (!entries.length) {
            return;
        }

        if (window.ResizeObserver) {
            observer = new ResizeObserver(guard(onObserved));
            entries.forEach(function (entry) {
                observer.observe(entry.scroll);
            });
        } else {
            window.addEventListener('resize', guard(schedule), {passive: true});
        }

        document.addEventListener('keydown', guard(onKeyDown));

        /* Anything that changes how wide the content wants to be, rather than
           how much room it has, has to ask for a fresh measurement: the
           observer guard above deliberately ignores those. */
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(guard(forceSchedule)).catch(function () { /* ignore */ });
        }
        document.addEventListener('load', guard(function (event) {
            var target = event.target;
            if (target && target.tagName === 'IMG' && target.closest('.rt-scroll')) {
                forceSchedule();
            }
        }), true);

        /* The first pass is synchronous, not scheduled. A page opened in a
           background tab runs no animation frames at all, so a deferred first
           measurement would leave every table unmeasured until the reader
           switched to the tab and then rearrange itself in front of them.
           Later passes stay coalesced — by then there is a frame to wait for. */
        evaluateAll();
    }

    /* Registered outside init() and immediately: the script is deferred, so it
       runs before the editor is built, and an edit screen has no reader
       tables for init() to find. The event bubbles to the document. */
    document.addEventListener('editor-tinymce::setup', guard(function (event) {
        var editor = event.detail && event.detail.editor;
        if (editor) {
            setupEditor(editor);
        }
    }));

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', guard(init));
    } else {
        guard(init)();
    }
})();
