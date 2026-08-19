<?php

/**
 * AI Chatbot — BookStack theme module entry point.
 *
 * Loaded automatically by ThemeService::readThemeActions() during app boot.
 * No core BookStack file is modified by this module.
 */

use BookStack\Facades\Theme;
use BookStack\Theming\ThemeEvents;
use BookStack\Theming\ThemeViews;
use BookStackAiChat\Config;
use BookStackAiChat\Http\ChatController;
use Illuminate\Routing\Router;

/**
 * Theme modules live outside Composer's autoload map, so register a PSR-4
 * style autoloader for this module's own classes.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'BookStackAiChat\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});

Theme::listen(ThemeEvents::ROUTES_REGISTER_WEB_AUTH, function (Router $router): void {
    if (!Config::instance()->configured()) {
        return;
    }

    $router->group(['prefix' => 'ai-chat'], function (Router $router): void {
        $router->post('/message', [ChatController::class, 'message'])->name('ai-chat.message');
    });
});

Theme::listen(ThemeEvents::THEME_REGISTER_VIEWS, function (ThemeViews $views): void {
    if (!Config::instance()->configured()) {
        return;
    }

    // base-body-end sits just before </body> in layouts/base.blade.php, which
    // every in-app layout extends. Export layouts do not include it, so the
    // widget stays out of PDF/HTML exports.
    $views->renderAfter('layouts.parts.base-body-end', 'ai-chatbot.widget');
});
