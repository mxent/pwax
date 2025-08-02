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
        animation: spin 1s linear infinite;
        z-index: 9999;
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
