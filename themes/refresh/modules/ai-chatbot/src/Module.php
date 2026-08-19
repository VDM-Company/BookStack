<?php

namespace BookStackAiChat;

use BookStack\Facades\Theme;

class Module
{
    /** Keep in step with bookstack-module.json; also used for asset cache-busting. */
    public const VERSION = '1.1.6';

    /**
     * Public asset URL. Files live under the module's `public/ai-chatbot/`
     * folder, which BookStack exposes at /theme/<theme>/ai-chatbot/<path>.
     */
    public static function asset(string $path): string
    {
        $url = url('/theme/' . Theme::getTheme() . '/ai-chatbot/' . ltrim($path, '/'));

        return $url . '?v=' . self::VERSION;
    }
}
