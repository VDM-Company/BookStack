<?php

/**
 * Google Auto-Link — BookStack theme module entry point.
 *
 * Loaded automatically by ThemeService::readThemeActions() during app boot.
 * No core BookStack file is modified by this module.
 */

use BookStack\Access\SocialAuthService;
use BookStack\Facades\Theme;
use BookStack\Theming\ThemeEvents;
use BookStackGoogleAutoLink\AutoLinkSocialAuthService;
use BookStackGoogleAutoLink\Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Theme modules live outside Composer's autoload map, so register a PSR-4
 * style autoloader for this module's own classes.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'BookStackGoogleAutoLink\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});

/**
 * SocialController type-hints the concrete SocialAuthService and core binds it
 * nowhere, so it is resolved from the container per request. Swapping in the
 * subclass here is enough to extend the social login flow without touching core.
 *
 * The closure must not return a value: ThemeService::dispatch() stops at the
 * first listener that returns non-null, which would suppress every APP_BOOT
 * listener registered after this one.
 */
Theme::listen(ThemeEvents::APP_BOOT, function (Application $app): void {
    if (Config::instance()->enabled()) {
        $app->bind(SocialAuthService::class, AutoLinkSocialAuthService::class);
    }
});
