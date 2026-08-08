{{--
    `$component` is the compiled Mxent\Pwax\Data\Component for this page. Nothing here
    uses it, but it is passed through deliberately: if you publish this view, it is what
    you would read to emit per-page <title> or Open Graph tags.
--}}
@props(['shell', 'component' => null, 'title' => null])
@php
    /** @var \Mxent\Pwax\Pwa\WebManifest $pwaxManifest */
    $pwaxManifest = app(\Mxent\Pwax\Pwa\WebManifest::class);

    $nonce = $shell->nonce();
    $nonceAttr = $nonce ? ' nonce="' . e($nonce) . '"' : '';
    $manifestPath = config('pwax.manifest_path', '/manifest.json');
    $themeColor = config('pwax.manifest.theme_color');
    $themeColorDark = config('pwax.head.theme_color_dark');
    $colorScheme = config('pwax.head.color_scheme');
    $appName = config('pwax.manifest.short_name') ?: config('pwax.manifest.name');
    $appleIcon = $pwaxManifest->appleTouchIcon();
    $icon = $pwaxManifest->favicon();
    $base = config('pwax.head.base');
    $csrf = $shell->csrfToken();
    $background = config('pwax.customization.init_background', '#ffffff');
    $spinnerBg = config('pwax.customization.init_spinner_bg', '#f3f3f3');
    $spinnerColor = config('pwax.customization.init_spinner_color', '#0c83ff');

    $documentTitle = $title ?: (config('pwax.head.title') ?: config('pwax.manifest.name'));
    $description = config('pwax.head.description') ?: config('pwax.manifest.description');
@endphp
{{--
    Charset, title, base, viewport, then the metadata, then the icons and the manifest,
    then what has to be fetched. The order is fixed so that reading the head of any Pwax
    application tells you the same story, and so that charset is settled before a byte of
    anything else is parsed.
--}}
<meta charset="utf-8">

@if ($documentTitle)
    <title>{{ $documentTitle }}</title>
@endif

{{--
    Off unless asked for. <base> changes how every relative URL in the document resolves
    — the src of every image in every component, and anything the runtime injects — so
    switching it on by default would silently rewrite working applications. Routing does
    not need it: a subdirectory install is already handled through the runtime's `base`.
--}}
@if ($base)
    <base href="{{ $base }}">
@endif

<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

@if ($description)
    <meta name="description" content="{{ $description }}">
@endif

{{--
    Absent on the offline shell, which is rendered with no session on purpose so that
    nothing user-specific is precached. The runtime treats a missing token as "send no
    CSRF header", and a 419 on the first write reloads the page to pick up a real one.
--}}
@if ($csrf)
    <meta name="csrf-token" content="{{ $csrf }}">
@endif

@if ($colorScheme)
    <meta name="color-scheme" content="{{ $colorScheme }}">
@endif

@if ($themeColor && $themeColorDark)
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="{{ $themeColor }}">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ $themeColorDark }}">
@elseif ($themeColor)
    <meta name="theme-color" content="{{ $themeColor }}">
@endif

@if ($icon)
    <link rel="icon" href="{{ $icon }}">
@endif

{{--
    iOS reads none of the manifest for the home screen. Without these tags an iPhone
    installs the app with a screenshot of the page as its icon, opens it in a Safari
    chrome rather than standalone, and labels it with the <title>.
--}}
@if ($appleIcon)
    <link rel="apple-touch-icon" href="{{ $appleIcon }}">
@endif

<link rel="manifest" href="{{ $manifestPath }}">

<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">

@if ($appName)
    <meta name="apple-mobile-web-app-title" content="{{ $appName }}">
    <meta name="application-name" content="{{ $appName }}">
@endif

{{--
    The framework is render-blocking by necessity — Vue Router and Pinia read the global
    `Vue` when they evaluate — so tell the browser to start fetching all of it while the
    head is still being parsed rather than discovering each one in turn.
--}}
@foreach ($shell->vendorPreloads() as $pwaxPreload)
    <link rel="preload" as="script" {{ $shell->attributes($pwaxPreload) }}>
@endforeach

<style{!! $nonceAttr !!}>
    .pwax-preloader {
        position: relative;
        /* dvh, not vh: on mobile browsers vh includes the collapsing toolbar, so a
           100vh box is taller than the visible area and the spinner sits off-centre. */
        min-height: 100dvh;
        overflow: hidden;
    }

    .pwax-preloader::before {
        content: '';
        position: absolute;
        inset: 0;
        background: {{ $background }};
        z-index: 9999;
    }

    .pwax-preloader::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 48px;
        height: 48px;
        margin: -24px 0 0 -24px;
        border: 5px solid {{ $spinnerBg }};
        border-top-color: {{ $spinnerColor }};
        border-radius: 50%;
        animation: pwax-spin 1s linear infinite;
        z-index: 10000;
    }

    @keyframes pwax-spin {
        to { transform: rotate(360deg); }
    }

    /* A spinning element is a known migraine and vestibular-disorder trigger. */
    @media (prefers-reduced-motion: reduce) {
        .pwax-preloader::after {
            animation-duration: 3s;
        }
    }

    .pwax-error {
        padding: 1.5rem;
        font-family: system-ui, sans-serif;
        color: #b91c1c;
    }

    .pwax-error h1 {
        font-size: 1.25rem;
        margin: 0 0 .5rem;
    }

    .pwax-retry {
        margin-top: 1rem;
        padding: .5rem 1rem;
        font: inherit;
        cursor: pointer;
    }

    .pwax-loading {
        padding: 1.5rem;
        font-family: system-ui, sans-serif;
    }
</style>

@foreach ($shell->stylesheets() as $style)
    <link rel="stylesheet" {{ $shell->attributes($style) }}>
@endforeach

@if (config('pwax.blade.head'))
    @include(config('pwax.blade.head'))
@endif
