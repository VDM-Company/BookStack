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
│   └── fonts/                          Geist + Geist Mono (SIL OFL)
└── src/                                stylesheet source
```

Three mechanisms do the work:

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

## Upgrading BookStack

Core is untouched, so upgrade normally. Two things to check afterwards:

1. **`layouts/parts/custom-head.blade.php`** — this is a full copy of a core view
   and is the only file that can silently drift. Diff it against
   `resources/views/layouts/parts/custom-head.blade.php` and re-apply the two
   `<link>` tags and the `<script>` if core has changed. It is short on purpose.
2. **Class names.** The theme targets core's CSS classes. If a major BookStack
   release renames or restructures a component, the corresponding rules in
   `src/` stop applying — they fail silently and safely, reverting that component
   to stock styling rather than breaking it.

Everything else — `theme.css`, `theme.js`, `functions.php`, `lang/` — is additive
and cannot conflict.

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
