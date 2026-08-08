{{--
    The SPA shell.

    Rendered on a full page load with the current component already embedded, so the
    first paint needs no further round trip. Publish it with
    `php artisan vendor:publish --tag=pwax-views` to customise.

    Available to this view:
        $pwaxInitial   JSON string: the current URL and its compiled component
        $pwaxComponent the Mxent\Pwax\Data\Component itself
        $pwaxShell     the Mxent\Pwax\Support\Shell assembling the runtime
--}}
@php
    /**
     * Resolved from the container only when the caller did not supply one.
     *
     * Two callers do supply one, and both need it honoured: the offline shell endpoint,
     * and the manifest builder, which renders this view with a deliberately request-free
     * Shell so that no per-session value — the CSRF token above all — can reach the
     * content hash. Overwriting it here would put a different token in every visitor's
     * manifest, giving each of them a private cache name and a full re-download of the
     * application.
     *
     * @var \Mxent\Pwax\Support\Shell $pwaxShell
     */
    $pwaxShell ??= app(\Mxent\Pwax\Support\Shell::class);
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
