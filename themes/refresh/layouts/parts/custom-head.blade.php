{{--
    Overrides core's `layouts.parts.custom-head` solely to load the theme's
    stylesheet, font and script. The original behaviour — rendering the
    admin-configured custom head content — is preserved verbatim at the bottom,
    so this override is a superset of core rather than a replacement.

    This is the ONLY core view the theme overrides. Everything else it does is
    achieved from CSS and a small script, because every overridden view is a
    full copy that silently drifts from upstream on update.

    On upgrade, diff this against resources/views/layouts/parts/custom-head.blade.php
    and re-apply the two <link> tags and the three <script> tags if core has changed.
--}}
@inject('headContent', 'BookStack\Theming\CustomHtmlHeadContentProvider')

@php
    $refreshTheme = config('view.theme');
    $refreshCssPath = theme_path('public/css/theme.css');
    $refreshJsPath = theme_path('public/js/theme.js');
    $refreshLightboxPath = theme_path('public/js/lightbox.js');
    $refreshTablesPath = theme_path('public/js/wide-tables.js');
    // Cache-bust on mtime so a rebuilt theme is picked up without a hard refresh.
    $refreshCssVer = $refreshCssPath && file_exists($refreshCssPath) ? filemtime($refreshCssPath) : '';
    $refreshJsVer = $refreshJsPath && file_exists($refreshJsPath) ? filemtime($refreshJsPath) : '';
    $refreshLightboxVer = $refreshLightboxPath && file_exists($refreshLightboxPath)
        ? filemtime($refreshLightboxPath) : '';
    $refreshTablesVer = $refreshTablesPath && file_exists($refreshTablesPath)
        ? filemtime($refreshTablesPath) : '';
@endphp

<link rel="preload" as="font" type="font/woff2" crossorigin
      href="{{ url('/theme/' . $refreshTheme . '/fonts/Geist-Variable.woff2') }}">
<link rel="stylesheet"
      href="{{ url('/theme/' . $refreshTheme . '/css/theme.css') }}?v={{ $refreshCssVer }}">
<script defer src="{{ url('/theme/' . $refreshTheme . '/js/theme.js') }}?v={{ $refreshJsVer }}"
        @if($cspNonce ?? false) nonce="{{ $cspNonce }}" @endif></script>
<script defer src="{{ url('/theme/' . $refreshTheme . '/js/lightbox.js') }}?v={{ $refreshLightboxVer }}"
        @if($cspNonce ?? false) nonce="{{ $cspNonce }}" @endif></script>
<script defer src="{{ url('/theme/' . $refreshTheme . '/js/wide-tables.js') }}?v={{ $refreshTablesVer }}"
        @if($cspNonce ?? false) nonce="{{ $cspNonce }}" @endif></script>

@if(!request()->routeIs('settings.category'))
<!-- Start: custom user content -->
{!! $headContent->forWeb() !!}
<!-- End: custom user content -->
@endif
