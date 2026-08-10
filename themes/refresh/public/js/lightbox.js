/**
 * "refresh" theme — image lightbox.
 *
 * BookStack wraps an inserted image in a link to the full-size original
 * (see resources/js/markdown/actions.ts), so clicking a screenshot navigates
 * away from the page you were reading. This intercepts that click and shows
 * the original in an overlay instead.
 *
 * A second script rather than an addition to theme.js: that file adds DOM
 * hooks for styling, this adds an interaction, and separating them means
 * either can be removed without the other.
 *
 * Nothing is scanned at load. One delegated listener resolves everything at
 * click time, so comments loaded or edited after page load need no
 * re-initialisation. If this file fails to load, images are still links to
 * the original — stock BookStack behaviour.
 */
(function() {
    'use strict';

    /* Where images are eligible. The same pair core itself uses in
       resources/js/code/index.mjs, so it is as stable as core gets. */
    var CONTENT_SELECTOR = '.page-content, .comment-box .content';

    /* The WYSIWYG editor's editable root is a same-document contenteditable
       div that ALSO carries the page-content class (resources/js/wysiwyg/
       index.ts:56 sets editorClass, ui/index.ts:8 sets contenteditable).
       Without this exclusion, clicking an image while editing would open the
       lightbox instead of selecting the image, making images uneditable. */
    var EDITOR_SELECTOR = '[contenteditable], .editor-content-area';

    var IMAGE_EXTENSION = /\.(png|jpe?g|gif|webp|avif|svg|bmp|ico)$/i;

    /* Only show the spinner if the original is genuinely slow, so a cached
       image never flashes one. */
    var SPINNER_DELAY = 150;

    var LABELS = {
        dialog: 'Image viewer',
        close: 'Close',
        prev: 'Previous image',
        next: 'Next image'
    };

    var ui = null;

    var state = {
        open: false,
        items: [],
        index: 0,
        lastFocus: null,
        loadToken: 0,
        zoomed: false,
        zoomable: false,
        dragged: false,
        prevOverflow: '',
        prevPadding: ''
    };

    function warn(err) {
        if (window.console && window.console.warn) {
            window.console.warn('refresh lightbox:', err);
        }
    }

    /**
     * Does this href point at an image file?
     *
     * Resolved through URL so relative and absolute hrefs behave identically
     * and a query string does not defeat the extension test. BookStack's own
     * uploads live under /uploads/images/ and are matched by path as well,
     * which covers storage backends that serve extensionless URLs.
     */
    function isImageHref(href) {
        if (!href) {
            return false;
        }
        var url;
        try {
            url = new URL(href, window.location.href);
        } catch {
            return false;
        }
        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return false;
        }
        return IMAGE_EXTENSION.test(url.pathname)
            || url.pathname.indexOf('/uploads/images/') !== -1;
    }

    /**
     * The URL the lightbox should show for an image, or null if this image is
     * not ours to intercept.
     *
     * An image wrapped in a link to something other than an image is an image
     * the author used as navigation. Returning null there leaves it alone.
     */
    function sourceFor(img) {
        var link = img.closest('a');
        if (link) {
            return isImageHref(link.getAttribute('href')) ? link.href : null;
        }
        return img.currentSrc || img.src || null;
    }

    function collectImages(container) {
        var all = Array.prototype.slice.call(container.querySelectorAll('img'));
        return all.filter(function(img) {
            return sourceFor(img) !== null;
        });
    }

    function element(tag, className, attributes) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        Object.keys(attributes || {}).forEach(function(key) {
            node.setAttribute(key, attributes[key]);
        });
        return node;
    }

    function buildUi() {
        var root = element('div', 'rl-backdrop', {
            role: 'dialog',
            'aria-modal': 'true',
            'aria-label': LABELS.dialog
        });
        root.hidden = true;

        var stage = element('div', 'rl-stage');
        var img = element('img', 'rl-img', {alt: ''});
        var spinner = element('div', 'rl-spinner', {'aria-hidden': 'true'});
        stage.appendChild(img);
        stage.appendChild(spinner);

        var closeButton = element('button', 'rl-btn rl-close', {
            type: 'button',
            'aria-label': LABELS.close
        });
        closeButton.textContent = '×';

        var prev = element('button', 'rl-btn rl-prev', {
            type: 'button',
            'aria-label': LABELS.prev
        });
        prev.textContent = '‹';
        prev.hidden = true;

        var next = element('button', 'rl-btn rl-next', {
            type: 'button',
            'aria-label': LABELS.next
        });
        next.textContent = '›';
        next.hidden = true;

        var bar = element('div', 'rl-bar');
        var caption = element('span', 'rl-caption');
        var counter = element('span', 'rl-counter');
        counter.hidden = true;
        bar.appendChild(caption);
        bar.appendChild(counter);

        root.appendChild(stage);
        root.appendChild(closeButton);
        root.appendChild(prev);
        root.appendChild(next);
        root.appendChild(bar);
        document.body.appendChild(root);

        ui = {
            root: root,
            stage: stage,
            img: img,
            spinner: spinner,
            bar: bar,
            caption: caption,
            counter: counter,
            close: closeButton,
            prev: prev,
            next: next
        };

        closeButton.addEventListener('click', close);
        root.addEventListener('click', onOverlayClick);
        img.addEventListener('load', onImageLoad);
    }

    function onOverlayClick(event) {
        if (event.target === ui.root || event.target === ui.stage) {
            close();
        }
    }

    function onImageLoad() {
        // Placeholder for the zoom affordance added in Task 4.
    }

    /**
     * Hide the page behind the overlay without letting it jump sideways when
     * the scrollbar disappears.
     */
    function lockScroll() {
        var body = document.body;
        var gap = window.innerWidth - document.documentElement.clientWidth;
        state.prevOverflow = body.style.overflow;
        state.prevPadding = body.style.paddingRight;
        body.style.overflow = 'hidden';
        if (gap > 0) {
            var current = parseInt(window.getComputedStyle(body).paddingRight, 10) || 0;
            body.style.paddingRight = (current + gap) + 'px';
        }
    }

    function unlockScroll() {
        document.body.style.overflow = state.prevOverflow;
        document.body.style.paddingRight = state.prevPadding;
    }

    /**
     * Show items[index].
     *
     * The thumbnail already on the page is painted first — it is in cache, so
     * it appears instantly — and the full-size original replaces it once it
     * has decoded. loadToken invalidates an in-flight load if the user moves
     * on before it finishes.
     */
    function show(index) {
        var item = state.items[index];
        if (!item) {
            return;
        }
        state.index = index;
        state.loadToken += 1;
        var token = state.loadToken;

        var thumb = item.currentSrc || item.src;
        var full = sourceFor(item);
        var caption = item.getAttribute('alt') || '';

        ui.img.alt = caption;
        ui.caption.textContent = caption;
        ui.caption.hidden = caption === '';
        ui.bar.hidden = caption === '';
        ui.root.classList.remove('rl-loading');

        if (thumb) {
            ui.img.src = thumb;
        }
        if (!full || full === thumb) {
            return;
        }

        var timer = window.setTimeout(function() {
            if (token === state.loadToken) {
                ui.root.classList.add('rl-loading');
            }
        }, SPINNER_DELAY);

        var loader = new Image();
        loader.onload = function() {
            window.clearTimeout(timer);
            if (token !== state.loadToken) {
                return;
            }
            ui.root.classList.remove('rl-loading');
            ui.img.src = full;
        };
        loader.onerror = function() {
            // Keep the thumbnail on screen; nothing should look broken.
            window.clearTimeout(timer);
            if (token === state.loadToken) {
                ui.root.classList.remove('rl-loading');
            }
        };
        loader.src = full;
    }

    function openAt(items, index) {
        if (!ui) {
            buildUi();
        }
        state.items = items;
        state.lastFocus = document.activeElement;
        show(index);
        ui.root.hidden = false;
        // Next frame, so the opening transition has a state to animate from.
        window.requestAnimationFrame(function() {
            ui.root.classList.add('rl-open');
        });
        lockScroll();
        state.open = true;
        ui.close.focus();
    }

    function close() {
        if (!state.open) {
            return;
        }
        state.open = false;
        state.loadToken += 1;
        ui.root.classList.remove('rl-open', 'rl-loading');
        ui.root.hidden = true;
        ui.img.removeAttribute('src');
        unlockScroll();
        if (state.lastFocus && state.lastFocus.focus) {
            state.lastFocus.focus();
        }
        state.lastFocus = null;
        state.items = [];
    }

    /**
     * Everything that decides whether a click is ours.
     *
     * preventDefault() happens last, and only once the overlay is actually
     * open, so a throw anywhere above leaves the plain link working.
     */
    function onClick(event) {
        if (event.defaultPrevented || event.button !== 0) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        if (!event.target || !event.target.closest) {
            return;
        }
        var img = event.target.closest('img');
        if (!img || img.closest(EDITOR_SELECTOR)) {
            return;
        }
        var container = img.closest(CONTENT_SELECTOR);
        if (!container || sourceFor(img) === null) {
            return;
        }
        var items = collectImages(container);
        var index = items.indexOf(img);
        if (index === -1) {
            return;
        }
        openAt(items, index);
        event.preventDefault();
    }

    function onKeyDown(event) {
        if (!state.open) {
            return;
        }
        if (event.key === 'Escape') {
            close();
            event.preventDefault();
        } else if (event.key === 'Tab') {
            trapFocus(event);
        }
    }

    /**
     * Keep Tab inside the dialog. Only the visible controls take part, so a
     * single-image lightbox traps on the close button alone.
     */
    function trapFocus(event) {
        var focusable = [ui.prev, ui.next, ui.close].filter(function(node) {
            return !node.hidden;
        });
        if (!focusable.length) {
            return;
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        var active = document.activeElement;
        var inside = ui.root.contains(active);
        if (event.shiftKey && (active === first || !inside)) {
            last.focus();
            event.preventDefault();
        } else if (!event.shiftKey && (active === last || !inside)) {
            first.focus();
            event.preventDefault();
        }
    }

    function guard(handler) {
        return function(event) {
            try {
                handler(event);
            } catch (err) {
                warn(err);
            }
        };
    }

    // Attached immediately rather than on DOMContentLoaded: the script is
    // deferred, and nothing here touches the DOM until a click arrives.
    document.addEventListener('click', guard(onClick));
    document.addEventListener('keydown', guard(onKeyDown));
})();
