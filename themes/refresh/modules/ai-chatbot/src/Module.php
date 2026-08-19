<?php

namespace BookStackAiChat;

use BookStack\Facades\Theme;

class Module
{
    /** Keep in step with bookstack-module.json; also used for asset cache-busting. */
    public const VERSION = '1.2.0';

    /**
     * Public asset URL. Files live under the module's `public/ai-chatbot/`
     * folder, which BookStack exposes at /theme/<theme>/ai-chatbot/<path>.
     */
    public static function asset(string $path): string
    {
        $url = url('/theme/' . Theme::getTheme() . '/ai-chatbot/' . ltrim($path, '/'));

        return $url . '?v=' . self::VERSION;
    }

    /**
     * Root-relative URL for one of the widget's own endpoints.
     *
     * The widget fetches these with `credentials: 'same-origin'`. url() builds
     * an absolute URL from APP_URL, and an APP_URL whose scheme or host does
     * not match what the browser is actually on counts as a different origin:
     * no session cookie is sent, Laravel starts an empty session and the POST
     * comes back as a CSRF mismatch. A path is same-origin by construction,
     * and still carries any subdirectory the install is served from.
     */
    public static function path(string $path): string
    {
        $absolute = url($path);

        return (string) (parse_url($absolute, PHP_URL_PATH) ?: '/' . ltrim($path, '/'));
    }
}
