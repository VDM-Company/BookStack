<?php

/**
 * Verifies the module is correctly attached to BookStack's theme system.
 *
 * This is the suite to run after a BookStack upgrade: it fails loudly if a
 * theme event, view path or query helper the module depends on has moved.
 */

require __DIR__ . '/bootstrap.php';

use BookStack\Facades\Theme;
use BookStackAiChat\Config;
use BookStackAiChat\Module;

$checks = new Checks('Theme system wiring');

$checks->section('Module discovery');

$modules = Theme::getModules();
$checks->that('a theme is active', Theme::getTheme() !== '', Theme::getTheme());
$checks->that('module discovered', isset($modules['ai-chatbot']));

$module = $modules['ai-chatbot'] ?? null;

if ($module === null) {
    $checks->finish();
}

$checks->same('metadata name', 'AI Chatbot', $module->name);
$checks->same('metadata version', 'v' . Module::VERSION, $module->getVersion());

$checks->section('Core APIs the module depends on');

foreach ([
    'ROUTES_REGISTER_WEB_AUTH',
    'THEME_REGISTER_VIEWS',
] as $event) {
    $checks->that("ThemeEvents::{$event} still exists", defined(BookStack\Theming\ThemeEvents::class . '::' . $event));
}

foreach ([
    BookStack\Search\SearchRunner::class => ['searchEntities'],
    BookStack\Search\SearchOptions::class => ['fromString'],
    BookStack\Entities\Queries\PageQueries::class => ['visibleForContent'],
    BookStack\Entities\Queries\BookQueries::class => ['visibleForList'],
    BookStack\Entities\Tools\Markdown\HtmlToMarkdown::class => ['convert'],
    BookStack\Http\HttpRequestService::class => ['buildClient'],
    BookStack\Uploads\Image::class => [],
    BookStack\Uploads\ImageService::class => ['streamImageFromStorageResponse'],
    BookStack\Uploads\Attachment::class => ['getUrl'],
] as $class => $methods) {
    $short = class_basename($class);
    $checks->that("{$short} exists", class_exists($class));

    foreach ($methods as $method) {
        $checks->that("{$short}::{$method}() exists", method_exists($class, $method));
    }
}

$checks->that(
    'Image::scopeVisible() exists',
    method_exists(BookStack\Uploads\Image::class, 'scopeVisible'),
);
$checks->that(
    'PageImages extractor loads',
    class_exists(BookStackAiChat\Knowledge\PageImages::class)
        && method_exists(BookStackAiChat\Knowledge\PageImages::class, 'extract'),
);

$checks->that(
    'layouts.parts.base-body-end still exists',
    is_file(base_path('resources/views/layouts/parts/base-body-end.blade.php')),
);

$checks->section('View resolution');

$finder = app('view')->getFinder();

try {
    $path = $finder->find('ai-chatbot.widget');
    $checks->that('widget view resolves to this module', str_contains($path, 'modules/ai-chatbot/views'), $path);
} catch (Throwable $exception) {
    $checks->that('widget view resolves to this module', false, $exception->getMessage());
}

try {
    app('blade.compiler')->compileString(file_get_contents(
        $module->path('views/ai-chatbot/widget.blade.php')
    ));
    $checks->that('widget view compiles', true);
} catch (Throwable $exception) {
    $checks->that('widget view compiles', false, $exception->getMessage());
}

$checks->section('Public assets');

foreach (['chat.css', 'chat.js'] as $asset) {
    $found = Theme::findFirstFile("public/ai-chatbot/{$asset}");
    $checks->that("{$asset} is locatable by the theme controller", $found !== null && str_contains((string) $found, 'modules/ai-chatbot'));
}

$checks->that(
    'asset URLs point at the theme route',
    str_contains(Module::asset('chat.css'), '/theme/' . Theme::getTheme() . '/ai-chatbot/chat.css?v='),
    Module::asset('chat.css'),
);

$checks->section('Translations');

$strings = trans('aichat');
$checks->that('module lang group loads', is_array($strings) && isset($strings['launcher']));

$javascript = file_get_contents($module->path('public/ai-chatbot/chat.js'));
preg_match_all("/this\.t\('([a-z_]+)'/", $javascript, $matches);
$missing = array_values(array_diff(array_unique($matches[1]), array_keys(is_array($strings) ? $strings : [])));
$checks->same('every string the UI asks for is defined', [], $missing);

$checks->section('Route registration');

$routes = collect(app('router')->getRoutes()->getRoutes())
    ->filter(fn($route) => str_starts_with($route->uri(), 'ai-chat'));

if (!Config::instance()->configured()) {
    $checks->that(
        'message route still exists without an API key',
        $routes->contains(fn ($route) => $route->uri() === 'ai-chat/message'),
    );
    $checks->finish();
}

$checks->that(
    'message route exists',
    $routes->contains(fn ($route) => $route->uri() === 'ai-chat/message'),
);
$checks->that(
    'client-error route exists',
    $routes->contains(fn ($route) => $route->uri() === 'ai-chat/client-error'),
);
$checks->that(
    'image stream route exists',
    $routes->contains(fn ($route) => $route->uri() === 'ai-chat/image/{path}'),
);

try {
    $matched = app('router')->getRoutes()->match(
        Illuminate\Http\Request::create('/ai-chat/image/uploads/images/gallery/2026-08/uMYr887hjyJHjW2i-fault-triage.png', 'GET')
    );
    $checks->same('image proxy matches a nested gallery path', 'ai-chat.image', $matched->getName());
} catch (Throwable $exception) {
    $checks->that('image proxy matches a nested gallery path', false, $exception->getMessage());
}

$checks->that(
    'token refresh route exists',
    $routes->contains(fn ($route) => $route->uri() === 'ai-chat/token'),
);

$message = $routes->first(fn ($route) => $route->uri() === 'ai-chat/message');
$middleware = $message ? $message->gatherMiddleware() : [];

$checks->same('message accepts POST only', ['POST'], $message ? $message->methods() : []);
$checks->that('runs in the web middleware group', in_array('web', $middleware, true), implode(', ', $middleware));
$checks->that('requires authentication', in_array('auth', $middleware, true));

// The `web` group is what starts the session. Without it VerifyCsrfToken has
// no session token to compare against and every POST is a 419, so pin the
// middleware that actually has to be there rather than just the group name.
$expanded = app('router')->getMiddlewareGroups()['web'] ?? [];
$checks->that(
    'web group starts the session',
    in_array(BookStack\Http\Middleware\StartSessionExtended::class, $expanded, true),
);
$checks->that(
    'web group verifies CSRF tokens',
    in_array(BookStack\Http\Middleware\VerifyCsrfToken::class, $expanded, true),
);
// CSRF protection stays on: the fix is a token refresh in the widget, not an
// exemption. Guard against anyone "fixing" a future 419 by listing the route.
$except = (new ReflectionClass(BookStack\Http\Middleware\VerifyCsrfToken::class))
    ->getDefaultProperties()['except'] ?? [];
$exempt = array_filter($except, fn ($pattern) => str_contains((string) $pattern, 'ai-chat'));
$checks->same('no ai-chat route is exempt from CSRF checks', [], array_values($exempt));

// Every route the widget calls must be auth-gated: the image proxy streams
// private wiki images, client-error writes to the log, and token hands out a
// session's CSRF token.
foreach (['ai-chat/token', 'ai-chat/client-error', 'ai-chat/image/{path}'] as $uri) {
    $route = $routes->first(fn ($candidate) => $candidate->uri() === $uri);
    $gathered = $route ? $route->gatherMiddleware() : [];

    $checks->that("{$uri} runs in the web group", in_array('web', $gathered, true));
    $checks->that("{$uri} requires authentication", in_array('auth', $gathered, true));
    $checks->that("{$uri} is read-only", $route && in_array('GET', $route->methods(), true) && !in_array('POST', $route->methods(), true));
}

// Absolute URLs built from a stale APP_URL are a different origin to the
// browser, so no session cookie is sent and the POST 419s. Paths cannot drift.
foreach (['/ai-chat/message', '/ai-chat/token', '/ai-chat/client-error', '/ai-chat/image'] as $endpoint) {
    $checks->that(
        "Module::path('{$endpoint}') is root-relative",
        str_starts_with(Module::path($endpoint), '/') && !str_contains(Module::path($endpoint), '://'),
        Module::path($endpoint),
    );
}

$checks->that('controller class loads', class_exists(BookStackAiChat\Http\ChatController::class));
$checks->that('token action exists', method_exists(BookStackAiChat\Http\ChatController::class, 'token'));
$checks->that('SSE response class loads', class_exists(BookStackAiChat\Http\SseResponse::class));
$checks->that('image controller class loads', class_exists(BookStackAiChat\Http\ImageController::class));
$checks->that('error reporter loads', class_exists(BookStackAiChat\ErrorReport::class));

$checks->finish();
