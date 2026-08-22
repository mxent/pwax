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

    // The CSP nonce for this document's inline blocks, resolved once. `pwax.csp.nonce`
    // may be a callable, and an application that returns a fresh value per call would
    // otherwise stamp a different nonce on each block and have every one of them refused.
    $pwaxNonce = $pwaxShell->nonce();
    $pwaxNonceAttr = $pwaxNonce ? ' nonce="' . e($pwaxNonce) . '"' : '';
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
    <x-pwax::includes.head :shell="$pwaxShell" :component="$pwaxComponent ?? null" :head="$pwaxHead ?? null" :title="$pwaxTitle ?? null" :prerendered="$pwaxSsr ?? false" :importStyles="$pwaxImportStyles ?? []" :headStyles="$pwaxHeadStyles ?? []" />
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

        A prerendered page does not get the class at all. The spinner is drawn by a
        `::before` pseudo-element with `inset: 0` and `z-index: 9999` — an opaque cover over
        whatever is inside — so a prerendered page kept it hidden behind a loading indicator
        until JavaScript booted, and hid it from a visitor without JavaScript permanently.
        That is the one visitor the prerender is for.

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
    @php($pwaxIsPrerendered = isset($pwaxPrerendered) && $pwaxPrerendered)
    @if ($pwaxIsPrerendered)
        {{--
            The prerendered page, and nothing else inside this element — not even a newline.

            Vue hydrates from `container.firstChild`. Indent the markup and that first child
            is a whitespace text node where the virtual DOM expects an element, which is a
            mismatch on the very first node it looks at: Vue drops the text node, renders the
            page from scratch *before* the next sibling, and leaves the server's markup where
            it was. The visitor sees the page twice. So the tag, the echo and the closing tag
            share one line, and the `@if` lives outside the element rather than inside it.

            Trusted server output from the Node SSR bridge, so raw echo is correct — the same
            trust level as the JSON islands below.
        --}}
        <div id="pwax" tabindex="-1" data-pwax-prerendered>{!! $pwaxPrerendered !!}</div>

        {{--
            Markup a settle-mode prerender found in `<body>` outside the mount element — a
            toast container, a modal portal, a cookie banner appended in `mounted()`. It is
            part of the page the browser paints, so a crawler reading this document should
            see it too.

            Beside the mount element rather than inside it, which is where the application
            put it. Marked so the runtime can clear it before it re-renders: the client
            builds its own copy of everything here, and leaving the server's would show each
            of them twice.

            Trusted server output from the Node SSR bridge, so a raw echo is correct — the
            same trust level as the prerendered markup above.
        --}}
        @foreach ($pwaxBodyHtml ?? [] as $pwaxBodyNode)
            <div data-pwax-settle-body>{!! $pwaxBodyNode !!}</div>
        @endforeach
    @else
        <div id="pwax" class="pwax-preloader" tabindex="-1">
            <span class="pwax-sr-only" role="status">Loading</span>
            @yield('content')
        </div>
    @endif

    @if ($pwaxIsPrerendered)
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
            {{--
                The preloader is a spinner waiting for a runtime that will never boot.

                Carries the nonce like every other inline block the package emits. Without
                it, an application running a strict `style-src 'nonce-…'` had this one rule
                refused — and the rule's whole job is to lift an opaque, full-viewport cover
                off the message below it. The visitor who has JavaScript off, which is the
                only visitor who ever reaches this markup, was left looking at a spinner.
            --}}
            <style{!! $pwaxNonceAttr !!}>.pwax-preloader{display:none}</style>
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
