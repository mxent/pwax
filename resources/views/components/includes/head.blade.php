<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

@php
    $themeColor = config('pwax.manifest.theme_color');
    $manifestPath = config('pwax.manifest_path', '/manifest.webmanifest');
@endphp

@if ($themeColor)
    <meta name="theme-color" content="{{ $themeColor }}">
@endif

<link rel="manifest" href="{{ $manifestPath }}">

<style>
    .preloader {
        position: relative;
        height: 100vh;
        width: 100vw;
        overflow: hidden;
    }

    .preloader:before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: #fff;
        z-index: 9999;
    }

    .preloader:after {
        content: '';
        position: absolute;
        transform: translate(-50%, -50%);
        top: 50%;
        left: 50%;
        width: 60px;
        height: 60px;
        margin: -30px 0 0 -30px;
        border: 6px solid {{ config('pwax.customization.init_spinner_bg', '#f3f3f3') }};
        border-top-color: {{ config('pwax.customization.init_spinner_color', '#0c83ff') }};
        border-radius: 50%;
        animation: pwax-spin 1s linear infinite;
        z-index: 9999;
    }

    @keyframes pwax-spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .pwax-error {
        padding: 1rem;
        color: #b91c1c;
        font-family: sans-serif;
    }
</style>

@foreach (config('pwax.styles', []) as $style)
    <link rel="stylesheet" href="{{ $style }}">
@endforeach

@if(config('pwax.blade.head'))
    @include(config('pwax.blade.head'))
@endif
