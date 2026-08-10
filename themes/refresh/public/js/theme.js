/**
 * "refresh" theme behaviour.
 *
 * Deliberately tiny. Its only job is to add hooks that core does not emit, so
 * the rest of the design can stay in CSS and the theme does not have to
 * override Blade views (an overridden view is a full copy that silently drifts
 * from upstream on every update).
 *
 * Two hooks:
 *   1. data-refresh-current  — marks the nav link for the current section, so
 *      the header can show where you are. Core emits no active state at all.
 *   2. .refresh-destructive  — marks the confirm button on delete/destroy
 *      screens, which core styles identically to "Save".
 *
 * Everything degrades to stock BookStack if this file fails to load.
 */
(function () {
    'use strict';

    /**
     * Mark the primary nav link matching the current section.
     *
     * Matched on the link's own path rather than a hardcoded list, so it keeps
     * working if BookStack adds or renames a nav item. The longest matching
     * path wins, so /settings/users marks Users rather than Settings.
     */
    function markCurrentNavLink() {
        var links = document.querySelectorAll('header .links a[href]');
        if (!links.length) return;

        var here = window.location.pathname.replace(/\/+$/, '') || '/';
        var best = null;
        var bestLength = 0;

        links.forEach(function (link) {
            var path;
            try {
                path = new URL(link.href, window.location.origin).pathname.replace(/\/+$/, '');
            } catch (e) {
                return;
            }
            if (!path || path === '') return;

            var matches = here === path || here.indexOf(path + '/') === 0;
            if (matches && path.length > bestLength) {
                best = link;
                bestLength = path.length;
            }
        });

        if (best) {
            best.setAttribute('data-refresh-current', 'true');
            // aria-current="true" rather than "page": these are section links,
            // and on a nested page they are not the current page itself.
            best.setAttribute('aria-current', 'true');
        }
    }

    /**
     * Give irreversible actions their own colour.
     *
     * Core submits every delete confirmation through the plain primary button,
     * so a permanent destroy and a routine save look identical. Scoped to the
     * confirmation screens themselves via the form's method-spoofing field, so
     * it cannot catch an unrelated submit button.
     */
    function markDestructiveButtons() {
        var forms = document.querySelectorAll('form');

        forms.forEach(function (form) {
            var method = form.querySelector('input[name="_method"]');
            var isDelete = method && String(method.value).toUpperCase() === 'DELETE';

            // Recycle-bin permanent destroy posts without method spoofing.
            var action = form.getAttribute('action') || '';
            var isDestroy = /\/(destroy|empty-recycle-bin)(\/|$)/.test(action);

            if (!isDelete && !isDestroy) return;

            var submit = form.querySelector('button[type="submit"].button, button.button[type="submit"]');
            if (submit) submit.classList.add('refresh-destructive');
        });
    }

    function init() {
        try {
            markCurrentNavLink();
            markDestructiveButtons();
        } catch (e) {
            // Never let the theme break the page.
            if (window.console && console.warn) {
                console.warn('refresh theme:', e);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
