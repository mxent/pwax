{{--
    The SPA shell.

    Rendered on a full page load with the current component already embedded, so the
    first paint needs no further round trip. Publish it with
    `php artisan vendor:publish --tag=pwax-views` to customise.

    Available to this view:
        $pwaxInitial   JSON string: the current URL and its compiled component
        $pwaxComponent the Mxent\Pwax\Data\Component itself
--}}
@php
    /** @var \Mxent\Pwax\Support\Shell $pwaxShell */
    $pwaxShell = app(\Mxent\Pwax\Support\Shell::class);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <x-pwax::includes.head :shell="$pwaxShell" :component="$pwaxComponent ?? null" />
    @stack('pwax-head')
</head>

<body>
    {{--
        `pwax-preloader` shows the spinner until the runtime mounts and removes the
        class. Content rendered inside is replaced on mount.
    --}}
    <div id="pwax" class="pwax-preloader" role="status" aria-live="polite" aria-label="Loading">
        @yield('content')
    </div>

    <x-pwax::includes.foot :shell="$pwaxShell" :initial="$pwaxInitial ?? null" />
    @stack('pwax-foot')
</body>

</html>
