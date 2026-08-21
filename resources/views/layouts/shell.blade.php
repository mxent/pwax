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
{{--
    `dir` alongside `lang`, because one without the other is not enough for a right-to-left
    locale: the language is declared and the layout still runs the wrong way. `auto` is the
    default, which lets the browser decide from the first strong character — right far more
    often than a hardcoded `ltr`, and overridable with `pwax.manifest.dir`.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $pwaxShell->direction() }}">

<head>
    <x-pwax::includes.head :shell="$pwaxShell" :component="$pwaxComponent ?? null" :head="$pwaxHead ?? null" :title="$pwaxTitle ?? null" />
    @stack('pwax-head')
</head>

<body>
    {{--
        First in the tab order and invisible until focused. A single-page application is
        one long document to a keyboard user, and without this every navigation means
        tabbing back through the whole of whatever the last page left on screen.
    --}}
    <a class="pwax-skip-link" href="#pwax">Skip to content</a>

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
    <div id="pwax" class="pwax-preloader" tabindex="-1"@if (isset($pwaxPrerendered) && $pwaxPrerendered) data-pwax-prerendered @endif>
        @if (isset($pwaxPrerendered) && $pwaxPrerendered)
            {{-- The prerendered page content. Trusted server output from the Node SSR bridge, so raw echo is correct — same trust level as the JSON islands below. The runtime hydrates this DOM rather than replacing it. --}}
            {!! $pwaxPrerendered !!}
        @else
            <span class="pwax-sr-only" role="status">Loading</span>
            @yield('content')
        @endif
    </div>

    @if (isset($pwaxPrerendered) && $pwaxPrerendered)
        {{--
            The page's content is already in the document, prerendered by the Node SSR
            bridge. JavaScript is only needed for interactivity and client-side
            navigation, so the no-JS message is minimal rather than a wall — and the
            preloader-hiding style is not emitted, because the prerendered content lives
            inside `.pwax-preloader` and hiding it would hide the page.
        --}}
        <noscript>
            <div class="pwax-screen pwax-noscript-hint" role="status">
                <p>Enable JavaScript for full interactivity.</p>
            </div>
        </noscript>
    @else
        {{--
            Said once, plainly, to whoever has JavaScript off or blocked.

            This application is rendered in the browser: the page's markup is compiled from a
            payload in this document by Vue, so there is nothing to progressively enhance and
            nothing useful to put here instead. Saying so is better than the alternative, which
            is a spinner that never stops.
        --}}
        <noscript>
            {{-- The preloader is a spinner waiting for a runtime that will never boot. --}}
            <style>.pwax-preloader{display:none}</style>
            <div class="pwax-screen" role="alert">
                <div class="pwax-screen__panel">
                    <p class="pwax-screen__code">JavaScript</p>
                    <h1 class="pwax-screen__title">This app needs JavaScript</h1>
                    <p class="pwax-screen__message">Enable it for this site, then reload the page.</p>
                </div>
            </div>
        </noscript>
    @endif

    {{--
        Where the runtime announces a client-side navigation.

        A browser announces a page change on its own. A router does not — it swaps the DOM
        and leaves a screen-reader user with no signal that anything happened, which is the
        one thing an SPA has to put back by hand.
    --}}
    <div id="pwax-announcer" class="pwax-sr-only" role="status" aria-live="polite"></div>

    <x-pwax::includes.foot :shell="$pwaxShell" :initial="$pwaxInitial ?? null" :state="$pwaxState ?? null" />
    @stack('pwax-foot')
</body>

</html>
