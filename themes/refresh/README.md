# refresh — a visual theme for BookStack

A design refresh delivered entirely through BookStack's theme system. **No core
file is modified**, so `git pull` from upstream BookStack merges cleanly.

## Install

Copy this directory into `themes/` and set:

```
APP_THEME=refresh
```

Then `php artisan config:clear`. No build step — the compiled CSS is committed.

## What it changes

- **Typography** — self-hosted Geist / Geist Mono variable fonts. Headings move
  from weight 400 with no tracking to 600 with optical negative tracking and
  tighter leading.
- **Surfaces** — one cool-tinted neutral ramp replacing core's mix of warm and
  cool greys, tinted elevation, softer radii, an off-black dark mode.
- **Interaction** — buttons lift on hover and press on click; focus rings move
  from `:focus` (which fires on mouse clicks) to `:focus-visible`; destructive
  confirmations get their own colour.
- **Contrast** — the neutral tiers are tuned so body text clears WCAG AA 4.5:1
  and secondary text clears 3:1, in both colour schemes.
- **Images** — clicking an image in page content or a comment opens it
  enlarged in an overlay, rather than navigating away to the raw image file.
  Arrow keys move through the images around it; clicking the image toggles
  actual size with drag-to-pan. Images an author linked to something other
  than an image still navigate.
- **Wide tables** — a table with more columns than the 840px reading column
  can hold scrolls sideways instead of being squeezed into it, and offers a
  full-screen view with a sticky header row and an optional frozen first
  column. Only tables measured as needing the room are affected; a table that
  fits renders exactly as stock. See below for what "needs the room" means.

## How it works

```
themes/refresh/
├── functions.php                       PHP entry — harmonised palette defaults
├── build.sh                            src/*.scss -> public/css/theme.css
├── layouts/parts/custom-head.blade.php the ONLY core view override
├── lang/en/errors.php                  copy fixes, merged over core
├── public/
│   ├── css/theme.css                   compiled, committed
│   ├── js/theme.js                     two DOM hooks core doesn't emit
│   ├── js/lightbox.js                  image lightbox
│   ├── js/wide-tables.js               scroll + full screen for wide tables
│   └── fonts/                          Geist + Geist Mono (SIL OFL)
└── src/                                stylesheet source
```

Five mechanisms do the work:

**1. A stylesheet loaded after core's.** `custom-head.blade.php` links
`theme.css`, which lands after `dist/styles.css` in `<head>`. Overrides at
*equal* specificity therefore win, so the theme almost never needs `!important`.

The one wrinkle is core's `lightDark()` SCSS mixin. For one declaration it emits
two rules:

```css
.card                { background-color: #FFF; }
html.dark-mode .card { background-color: #222; }
```

The second outranks anything written at the plain selector, so overriding only
`.card` would leave dark mode on core's value. The `ld()` mixin in
`src/_tools.scss` emits both halves. If you add a rule and it works in light mode
but not dark, this is why.

**2. Two DOM hooks in `theme.js`.** Core emits no "current nav item" state and no
class distinguishing a delete confirmation from a save, so the script adds
`data-refresh-current` and `.refresh-destructive`. Doing this in JS rather than by
overriding Blade views is deliberate — see below. Both degrade to stock BookStack
if the script fails to load.

**3. Palette via `setting-defaults`.** `functions.php` sets the default accent and
entity colours at `APP_BOOT`. It does **not** set them in CSS, which would
override the administrator's colour pickers in *Settings → Customization* with no
way to change them back. `SettingService::get()` reads the database first and only
falls back to these defaults, so an explicit admin choice always wins.

**4. A delegated click listener in `lightbox.js`.** Image clicks in
`.page-content` and `.comment-box .content` open an overlay instead of
navigating. Nothing is scanned at load, so comments rendered after page load
need no re-initialisation. The one subtlety is that the WYSIWYG editor's
editable root is a same-document `contenteditable` div that *also* carries the
`page-content` class, so the handler explicitly excludes `[contenteditable]`
and `.editor-content-area` — without that, images could not be selected while
editing. The markdown preview needs no exclusion: it is a separate `about:blank`
iframe, so the parent document's listener never sees its clicks. If the script
fails to load, images remain plain links to the original.

**5. A measure-then-decide pass over content tables in `wide-tables.js`.**
Worth understanding before changing, because the behaviour is deliberately
narrow.

Core does not let a content table overflow. It sets `table-layout: fixed` and
`max-width: 100%` on `.page-content table`, and `word-break: break-word` on
every cell, so a table with more columns than the 840px reading column can hold
is not pushed off the page — it is compressed, down to a character or two per
line. The complaint "this table is too wide" is really "this table has been
crushed to fit".

Every content table is wrapped in a scroll container at load. All of them are
then unclamped at once, measured in a single layout pass, and the clamp is put
back on the ones that turned out to fit. Only a table that is still wider than
its container keeps the treatment: a scrollable region with fade hints at
whichever edge has more content, a keyboard-reachable and labelled scroll box,
and a **Full screen** button. Tables that fit come out of the pass byte for
byte as core rendered them — no wrapper padding, no button, no extra tab stop.

The threshold is not a pixel count. A table "needs the room" when its columns
cannot fit **without breaking words**, which is what the measurement asks by
restoring word-level wrapping before it measures. The practical consequence is
that a table which fits only because core is breaking words mid-word — `S/te/p`
down a narrow column — is left alone, because at word-level wrapping it still
fits. If that is not the trade-off you want, pass `true` instead of
`results[i].wide` to `setWide()` in `evaluateAll()` and every table keeps
`rt-wide`: they all move to content-proportional columns and word-level
wrapping, at the cost of changing how tables that already fit are laid out.

Four kinds of table are skipped outright, each for a reason: anything inside
`[contenteditable]` or `.editor-content-area` (wrapping there would put theme
markup into the HTML the author is about to save), `table.align-left` and
`table.align-right` (core floats these so text flows around them, and a float
inside a block wrapper no longer reaches the page), tables nested in another
table's cell, and anything already wrapped.

The full-screen viewer shows a **copy** of the table, with its `id` attributes
stripped. Moving the original would leave the page missing a table for anything
that reads it while the viewer is open — printing most obviously — and a
duplicated `bkmrk-*` id would make `getElementById` ambiguous for the pointer,
page includes and comment references, which all resolve by id. The viewer's
body carries core's `page-content` class so the copy keeps the typography it
was authored against and images inside it still reach the lightbox; the
stylesheet undoes that class's 840px cap for this one context.

If the script fails to load, every table renders as stock BookStack.

## Upgrading BookStack

Core is untouched, so upgrade normally. Two things to check afterwards:

1. **`layouts/parts/custom-head.blade.php`** — this is a full copy of a core view
   and is the only file that can silently drift. Diff it against
   `resources/views/layouts/parts/custom-head.blade.php` and re-apply the two
   `<link>` tags and the three `<script>` tags if core has changed. It is short
   on purpose.
2. **Class names.** The theme targets core's CSS classes. If a major BookStack
   release renames or restructures a component, the corresponding rules in
   `src/` stop applying — they fail silently and safely, reverting that component
   to stock styling rather than breaking it.

   The lightbox additionally depends on `.page-content`,
   `.comment-box .content` and `.editor-content-area`. If a release renames
   these, the lightbox stops matching and images revert to plain links — it
   fails safe, but check it after a major upgrade.

   `wide-tables.js` depends on the same class names, and its stylesheet answers
   four specific core declarations: `table-layout: fixed` and `max-width: 100%`
   on `.page-content table` (`resources/sass/_content.scss`), and
   `word-break: break-word` and `overflow: auto` on `table td, table th`
   (`resources/sass/_tables.scss`). If a release drops or moves any of those,
   re-check `src/_tables.scss` — the overrides become dead weight rather than
   breakage, but the measurement assumes core is still clamping.

Everything else — `theme.css`, `theme.js`, `lightbox.js`, `wide-tables.js`,
`functions.php`, `lang/` — is additive and cannot conflict.

## Editing

```bash
./build.sh              # compressed, what you commit
./build.sh expanded     # readable, for debugging
```

## What this theme deliberately does not do

The original version of this redesign also made accessibility and semantic fixes
to core markup: a single `<main>` landmark per page, real `<button>` elements for
the back-to-top and notification-dismiss controls, corrected `for` attributes on
several settings labels, and an `<h1>` on pages that had none.

**None of that is in this theme.** A theme can only change markup by overriding a
Blade view, and each override is a full copy that drifts from upstream on every
update — roughly 40 files would have been needed. Those fixes belong upstream, not
in a theme, so they are kept on the `redesign/design-system-refresh` branch of
this repository instead.

The practical consequence: this theme changes how BookStack *looks*, not how its
markup is *structured*. The contrast and focus-visibility improvements are here;
the landmark and control-semantics fixes are not.
