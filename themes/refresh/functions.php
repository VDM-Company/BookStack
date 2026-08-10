<?php

/**
 * "refresh" theme — PHP entry point.
 *
 * BookStack requires this file automatically when APP_THEME points at this
 * directory. It does one job: supply a harmonised default palette.
 *
 * Why not do this in theme.css?
 *
 * Core emits the accent colours per request as an inline <style> block from
 * layouts/parts/custom-styles.blade.php, reading them from the `app-color`,
 * `link-color`, `book-color` (etc.) settings. The theme stylesheet is linked
 * after that block, so hardcoding the palette at :root in CSS would win — and
 * would therefore silently override whatever the administrator has chosen in
 * Settings > Customization, with no way for them to change it back.
 *
 * Setting the *defaults* instead keeps the pickers working:
 * SettingService::get() reads the database first and only falls back to
 * config('setting-defaults.*'), so an explicit admin choice always wins and
 * this only changes what a fresh install starts from.
 *
 * The values keep BookStack's semantic hue distinctions — shelf, book, chapter,
 * page and draft still read as different things — but sit at a comparable
 * lightness and chroma so they look like one family rather than five unrelated
 * accents.
 */

use BookStack\Facades\Theme;
use BookStack\Theming\ThemeEvents;

Theme::listen(ThemeEvents::APP_BOOT, function () {
    config()->set([
        // Light scheme
        'setting-defaults.app-color-light'       => 'rgba(32,110,167,0.14)',
        'setting-defaults.link-color'            => '#1d659b',
        'setting-defaults.bookshelf-color'       => '#a34a55',
        'setting-defaults.book-color'            => '#0f7268',
        'setting-defaults.chapter-color'         => '#a75a12',
        'setting-defaults.page-color'            => '#2166a8',
        'setting-defaults.page-draft-color'      => '#6f52a8',

        // Dark scheme. The primary is deliberately kept dark: it fills the
        // whole header bar, and a lighter accent there glares. The link colour
        // carries the brightness instead, and the theme's focus ring uses it
        // for the same reason.
        'setting-defaults.app-color-dark'        => '#1b5c8c',
        'setting-defaults.app-color-light-dark'  => 'rgba(90,163,224,0.16)',
        'setting-defaults.link-color-dark'       => '#6fb1e8',
        'setting-defaults.bookshelf-color-dark'  => '#e07b86',
        'setting-defaults.book-color-dark'       => '#46b39d',
        'setting-defaults.chapter-color-dark'    => '#e0954d',
        'setting-defaults.page-color-dark'       => '#5aa3e0',
        'setting-defaults.page-draft-color-dark' => '#a68ce0',
    ]);
});
