# Image lightbox — verification harness

Drives the **real** `themes/refresh/public/js/lightbox.js` and the **real**
built `themes/refresh/public/css/theme.css` against BookStack's page markup in
a headless browser, and asserts the behaviour matrix in
`docs/superpowers/plans/2026-08-10-image-lightbox.md`.

This exists because the plan's Task 1 wanted a live BookStack instance via
Docker Compose, and the machine the work ran on had no Docker daemon. It is a
substitute for that behaviour gate, not a replacement for it — see *Limits*.

The theme itself still ships with no test dependency: nothing here lives under
`themes/`, and nothing in `themes/` refers to it.

## What it reproduces

- The fixture markup from the plan's Task 1 Step 5, verbatim, inside
  `.page-content` — five images covering linked, unlinked, linked-elsewhere,
  and small-original cases.
- A `.comment-box .content` container, so the "each container is its own
  gallery" rule can be checked.
- The WYSIWYG editable root as core actually builds it: a `contenteditable`
  div that *also* carries `page-content`, inside `.editor-content-area`.
- The markdown preview as core actually builds it: an `about:blank` iframe
  whose body class is set to `page-content`
  (`resources/js/markdown/display.ts:35`).

## Running it

```bash
npm install playwright          # anywhere; only needed to run this harness
node make-fixtures.js ./fixtures
node server.js &                # serves on http://localhost:8099
node verify.js
```

`make-fixtures.js` writes the PNGs the plan generates with `sips` — a 2400px
original, 320px thumbnails and a 180px small original — using a dependency-free
PNG encoder, so it works on any platform. The fixtures are generated rather
than committed; `LB_FIXTURES` overrides where the server looks for them.

Set `LB_CHROME` to point at a specific Chromium binary if you do not want
Playwright's bundled one.

Exit status is non-zero if any check fails.

## Limits

It cannot cover anything server-side. Three matrix rows are therefore still
unverified and want a real instance:

1. The page revision view (`.page-content page-revision`).
2. The Blade `<script>` tag in `layouts/parts/custom-head.blade.php` actually
   serving `lightbox.js`, with its mtime cache-bust and CSP nonce.
3. A live TinyMCE editor, as opposed to a faithful reproduction of its DOM
   shape.

Run the plan's Task 1 as written to close those.
