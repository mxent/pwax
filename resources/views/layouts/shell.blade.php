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
    <x-pwax::includes.head :shell="$pwaxShell" :component="$pwaxComponent ?? null" :title="$pwaxTitle ?? null" />
    @stack('pwax-head')
</head>

<body>
    {{--
        `pwax-preloader` covers the mount point with the spinner until the runtime mounts
        and removes the class. Content rendered inside is replaced on mount.

        This is the first load, and it is the browser's own wait: a document arriving. The
        progress bar has no part in it — that is for navigations, where the address bar
        does not move and nothing else would say a page is on its way. It is not rendered
        here at all; the runtime creates it on the first navigation slow enough to need
        one, so an application whose navigations are all fast never puts it in the
        document.

        The loading semantics are on their own element rather than on this one. They used
        to be here, and `role="status"` with `aria-live="polite"` is right for a spinner
        and badly wrong for an application root: the runtime removed the class on mount
        and left the attributes, so for the rest of the session every reactive text change
        anywhere in the app was announced, and the whole application was labelled
        "Loading". This element is a container; nothing about it is a status.
    --}}
    <div id="pwax" class="pwax-preloader">
        <span class="pwax-sr-only" role="status">Loading</span>
        @yield('content')
    </div>

    {{--
        Where the runtime announces a client-side navigation.

        A browser announces a page change on its own. A router does not — it swaps the DOM
        and leaves a screen-reader user with no signal that anything happened, which is the
        one thing an SPA has to put back by hand.
    --}}
    <div id="pwax-announcer" class="pwax-sr-only" role="status" aria-live="polite"></div>

    <x-pwax::includes.foot :shell="$pwaxShell" :initial="$pwaxInitial ?? null" />
    @stack('pwax-foot')
</body>

</html>
