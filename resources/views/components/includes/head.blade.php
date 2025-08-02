<style>
    .app-not-loaded {
        display: none !important;
    }

    .preloader {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: #fff;
    }

    .preloader:after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 60px;
        height: 60px;
        margin: -30px 0 0 -30px;
        border: 6px solid {{ config('pwax.init_spinner_bg', '#f3f3f3') }};
        border-top-color: {{ config('pwax.init_spinner_color', '#0c83ff') }};
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }
</style>

@foreach (config('pwax.styles', []) as $style)
    <link rel="stylesheet" href="{{ $style }}">
@endforeach

@if(config('pwax.blade.head'))
    @include(config('pwax.blade.head'))
@endif
