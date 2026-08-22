{{--
    `$component` is the compiled Mxent\Pwax\Data\Component for this page. If you publish
    this view, it is what you would read to emit per-page <title> or Open Graph tags.

    `$prerendered` says whether the response carries server-rendered HTML for this page.
    When it does, this page's own stylesheet has to be in the document before the markup
    is painted — see the block near the bottom.
--}}
@props(['shell', 'component' => null, 'head' => null, 'title' => null, 'prerendered' => false, 'importStyles' => []])
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
    /*
     * These land inside a <style> block, where Blade's escaping does not help: it escapes
     * for HTML, and CSS has its own way out. A value of `#fff; } html { display: none } .x {`
     * is a valid string and a broken page. Configuration is not user input, so this is
     * hardening rather than a hole — but a typo should cost one wrong colour, not the
     * whole stylesheet.
     */
    $pwaxColor = static function (string $key, string $fallback): string {
        $value = trim((string) config($key, $fallback));

        // Hex, the CSS colour functions, and the named keywords. Anything else, including
        // anything carrying a brace, a semicolon or a comment marker, is not a colour.
        return preg_match('/^(#[0-9a-f]{3,8}|[a-z]+|(rgb|rgba|hsl|hsla|oklch|lab)\([0-9a-z%.,\/\s+-]*\))$/i', $value) === 1
            ? $value
            : $fallback;
    };

    /*
     * A component's own stylesheet, safe to write into a <style> block.
     *
     * The runtime hands this same CSS to the DOM as `textContent`, which cannot terminate
     * the element it is in. Writing it into the document as markup can: an HTML parser ends
     * a <style> at the first `</style`, wherever it appears, and a component's CSS is Blade
     * output — so it may carry an interpolated value that the author did not write. Nothing
     * valid in CSS needs that sequence, so neutering it costs nothing and closes the one way
     * out of the block.
     */
    $pwaxCss = static fn (string $css): string => (string) preg_replace('#</(style)#i', '<\\/$1', $css);

    $background = $pwaxColor('pwax.customization.init_background', '#ffffff');
    $spinnerBg = $pwaxColor('pwax.customization.init_spinner_bg', '#f3f3f3');
    $spinnerColor = $pwaxColor('pwax.customization.init_spinner_color', '#0c83ff');

    // The navigation progress bar. Defaults to the spinner's colour so an application that
    // set one has already set the other.
    $spinner = (bool) config('pwax.customization.init_spinner', true);
    $progressColor = $pwaxColor('pwax.progress.color', $spinnerColor);
    $progressHeight = (int) config('pwax.progress.height', 3);
    $transitionMs = (int) config('pwax.transition.duration', 150);

    /**
     * The page's metadata, already merged with the application's defaults.
     *
     * Supplied by `ComponentResponse`, which resolves it once and puts the same object in
     * the payload — so a reload and a client-side navigation cannot end up describing the
     * document differently. Resolved here only for the callers that render this view
     * without a page at all: the offline shell, and the manifest builder.
     *
     * @var \Mxent\Pwax\Data\Head $pwaxHead
     */
    $pwaxHead = $head ?? app(\Mxent\Pwax\Pwa\HeadMeta::class)->resolve(
        $title ? new \Mxent\Pwax\Data\Head(title: $title) : null
    );

    $documentTitle = $pwaxHead->title;
    $description = $pwaxHead->description;
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

@if ($pwaxHead->canonical)
    <link rel="canonical" href="{{ $pwaxHead->canonical }}">
@endif

{{--
    The page's own tags, plus the Open Graph and Twitter ones derived from its title,
    description and canonical URL. `data-pwax-head` marks them as this package's to
    manage: the runtime replaces exactly these on a client-side navigation and leaves
    anything the application put in `@stack('pwax-head')` alone.
--}}
@foreach ($pwaxHead->meta as $pwaxMeta)
    <meta {{ $pwaxMeta['attribute'] }}="{{ $pwaxMeta['key'] }}" content="{{ $pwaxMeta['content'] }}" data-pwax-head>
@endforeach

{{--
    The page's translations. Marked managed like the tags above: a `hreflang` set left over
    from the previous route claims that page's translations are this one's, which is a
    worse answer than none — a search engine acting on it serves the wrong URL to the wrong
    language.
--}}
@foreach ($pwaxHead->alternates as $pwaxAlternate)
    <link rel="alternate" hreflang="{{ $pwaxAlternate['hreflang'] }}" href="{{ $pwaxAlternate['href'] }}" data-pwax-head>
@endforeach

{{--
    Structured data, one block per claim.

    Encoded with the same `JSON_HEX_*` flags as the runtime's JSON islands, which makes a
    `</script>` sequence unrepresentable: this is the one place in the head where a value
    from the database is written as markup rather than as an attribute, so the escaping has
    to hold against content nobody reviewed.

    It carries the nonce even though the block is data rather than code. A browser applies
    `script-src` to a `<script>` element by its tag, not by its `type`, so under a strict
    policy an un-nonced `application/ld+json` block is refused — silently, and only in
    production, where the policy is on and nobody is looking at the console.
--}}
@foreach ($pwaxHead->jsonLd as $pwaxSchema)
    <script type="application/ld+json" data-pwax-head{!! $nonceAttr !!}>{!! json_encode($pwaxSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endforeach

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

{{--
    The modules this page is about to import, named before it can ask for them.

    A component imported with @pwaxImport is fetched only once Vue has loaded, compiled
    this page's template and rendered it — a serial round trip after the framework is
    already up, for a URL the server knew while it was writing this document. The same
    goes for configured plugins and directives, which the runtime awaits before it mounts.
--}}
@foreach ($shell->modulePreloads($component) as $pwaxModule)
    <link rel="modulepreload" href="{{ $pwaxModule }}">
@endforeach

@foreach ($component?->externalScripts ?? [] as $pwaxExternalScript)
    <link rel="preload" as="script" href="{{ $pwaxExternalScript }}">
@endforeach

{{--
    A prerendered page's stylesheets are loaded, not merely hinted at.

    Ordinarily the runtime attaches them: `styles.link()` and `styles.acquire()` run as the
    page component is mounted, and a preload hint is the right thing here because the sheet
    is about to be requested by JavaScript anyway. A prerendered page has its markup in the
    document from the first byte, so waiting for JavaScript would mean painting the page
    unstyled and restyling it a moment later — and for a visitor without JavaScript, which
    is who the prerender is for, it would never be styled at all.

    `data-pwax-style` is the key the runtime's reference-counted style manager uses, and it
    carries the component's digest so that each page's stylesheet has its own identity.
    Marked with it, the inline block below is *adopted* on boot rather than duplicated, and
    is released like any other page stylesheet on the first navigation away.
--}}
@foreach ($component?->externalStyles ?? [] as $pwaxExternalStyle)
    @if ($prerendered)
        <link rel="stylesheet" href="{{ $pwaxExternalStyle }}">
    @else
        <link rel="preload" as="style" href="{{ $pwaxExternalStyle }}">
    @endif
@endforeach

@if ($prerendered && $component?->style)
    <style data-pwax-style="pwax:page:{{ $component->hash() }}"{!! $nonceAttr !!}>{!! $pwaxCss($component->style) !!}</style>
@endif

{{--
    The stylesheets of the components this page pulls in with `@pwaxImport`.

    Same reasoning as the page's own block above, and the same key: the runtime's style
    manager identifies an imported component's stylesheet by its module URL, so it adopts
    these rather than appending a second copy of each as the modules load.
--}}
@foreach ($importStyles as $pwaxImportUrl => $pwaxImportCss)
    <style data-pwax-style="{{ $pwaxImportUrl }}"{!! $nonceAttr !!}>{!! $pwaxCss($pwaxImportCss) !!}</style>
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

    @if ($spinner)
    /* The first load's indicator, covering the gap between the document arriving and the
       runtime mounting. `customization.init_spinner` turns it off for an application that
       renders its own skeleton into the mount element instead. */
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
    @endif

    /* Screens.
       The three states an application can be in with nothing of its own to show: a page
       that would not load, a runtime that would not start, and a URL with no offline copy.
       One layout for all of them, because to a visitor they are the same event — something
       did not work — and three different-looking apologies read as three different bugs.

       These take the application's own text colour and derive everything from it, rather
       than picking colours of their own. That is not laziness — it is the only thing that
       works. This screen paints inside your layout, on your background, and a rule that
       switched on `prefers-color-scheme` would put near-white text on the light page of
       an application that has no dark mode the moment the visitor's system had one. It
       cannot know your background, so it must not assume one.

       Every value is still overridable for an application that wants to: set
       --pwax-screen-fg and friends on :root in your own stylesheet, which loads after
       this one. */
    :root {
        --pwax-screen-accent: {{ $progressColor }};
        --pwax-screen-accent-fg: #ffffff;
    }

    .pwax-screen {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 60dvh;
        padding: 2rem 1.5rem;
        background: var(--pwax-screen-bg, transparent);
        color: var(--pwax-screen-fg, inherit);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }

    .pwax-screen__panel {
        width: 100%;
        max-width: 32rem;
        text-align: center;
    }

    /* The status, set apart rather than shouted. A large number is the house style of a
       server error page, and this is not the server — it is the application still running,
       telling you one page did not arrive. */
    .pwax-screen__code {
        margin: 0 0 1rem;
        font-size: .75rem;
        font-weight: 600;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: var(--pwax-screen-muted, inherit);
        /* Dimmed rather than greyed. A grey is a colour and would have to guess at the
           background; transparency reads as "quieter than the text" against any of them. */
        opacity: var(--pwax-screen-muted-opacity, .62);
    }

    .pwax-screen__code::after {
        content: '';
        display: block;
        width: 2.5rem;
        height: 1px;
        margin: .75rem auto 0;
        background: var(--pwax-screen-line, currentColor);
        opacity: .45;
    }

    .pwax-screen__title {
        margin: 0 0 .5rem;
        font-size: 1.375rem;
        font-weight: 600;
        letter-spacing: -.01em;
    }

    .pwax-screen__message {
        margin: 0;
        color: var(--pwax-screen-muted, inherit);
        opacity: var(--pwax-screen-muted-opacity, .72);
        overflow-wrap: anywhere;
    }

    .pwax-screen__actions {
        display: flex;
        flex-wrap: wrap;
        gap: .625rem;
        justify-content: center;
        margin-top: 1.75rem;
    }

    .pwax-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2.5rem;
        padding: 0 1.125rem;
        border: 1px solid transparent;
        border-radius: .5rem;
        background: var(--pwax-screen-accent);
        color: var(--pwax-screen-accent-fg);
        font: inherit;
        font-weight: 500;
        text-decoration: none;
        cursor: pointer;
    }

    /* Outlined in the text's own colour, so it is legible on whatever it is drawn over
       without knowing what that is. */
    .pwax-button--quiet {
        background: transparent;
        border-color: var(--pwax-screen-line, currentColor);
        color: inherit;
    }

    .pwax-button:hover {
        opacity: .9;
    }

    /* Never removed, only replaced. A keyboard user who cannot see the focus ring cannot
       find the retry button, which on this screen is the only way forward. */
    .pwax-button:focus-visible {
        outline: 2px solid var(--pwax-screen-accent);
        outline-offset: 2px;
    }

    /* The navigation progress bar.
       Fixed and scaled from the left, so it never participates in layout and never
       reflows the page underneath it — the whole point is that nothing else moves. */
    #pwax-progress {
        position: fixed;
        top: 0;
        left: 0;
        z-index: 2147483000;
        width: 100%;
        height: {{ $progressHeight }}px;
        background: {{ $progressColor }};
        transform: scaleX(0);
        transform-origin: 0 50%;
        opacity: 0;
        pointer-events: none;
        will-change: transform, opacity;
        transition: transform 200ms ease-out, opacity 200ms linear;
    }

    #pwax-progress.pwax-progress-visible {
        opacity: 1;
    }

    /* The completing stroke: full width, then fade. The width transition is quicker here
       so "done" reads as an arrival rather than one last slow crawl. */
    #pwax-progress.pwax-progress-done {
        opacity: 0;
        transition: transform 120ms ease-out, opacity 220ms linear 100ms;
    }

    /* Page transitions.
       The runtime wraps the page swap in `document.startViewTransition` (see
       `src/js/page.js`). The browser snapshots the outgoing page, commits the new one,
       and cross-fades between them in a single frame — no empty router-view between
       them, regardless of the duration. The default animation is a cross-fade; the
       rules below only set the duration (`transition.duration`) and keep the eased
       opacity curve the older Vue transition used.

       Opacity only — nothing that changes an element's size or position, because a
       transform on the outgoing page is a second kind of movement to look at, and the
       reason this exists is that navigation felt unsettled. Browsers without the
       View Transitions API do not match these pseudo-elements and the swap is
       synchronous, which is the previous behaviour preserved. */
    ::view-transition-old(root),
    ::view-transition-new(root) {
        animation-duration: {{ $transitionMs }}ms;
    }

    /* Motion is a preference, and both of these are motion. The progress bar keeps its
       colour and its position — it just stops sliding — and pages swap outright. */
    @media (prefers-reduced-motion: reduce) {
        #pwax-progress {
            transition: opacity 200ms linear;
        }

        ::view-transition-old(root),
        ::view-transition-new(root) {
            animation: none;
        }
    }

    /* Read by a screen reader, invisible to everyone else. Hiding it outright, or
       collapsing its visibility, would hide it from both. */
    .pwax-sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
        border: 0;
    }

    /* Off-screen until focused, then placed over the top-left corner. `:focus`, not
       `:focus-visible` — a skip link is reached by tabbing and by nothing else, so there
       is no case where it has focus and should stay hidden. */
    .pwax-skip-link {
        position: absolute;
        left: -9999px;
        top: 0;
        z-index: 2147483001;
        padding: 0.6rem 1rem;
        background: {{ $background }};
        color: inherit;
        font: inherit;
        text-decoration: underline;
        border: 1px solid currentColor;
        border-radius: 0 0 4px 0;
    }

    .pwax-skip-link:focus {
        left: 0;
    }
</style>

@foreach ($shell->stylesheets() as $style)
    <link rel="stylesheet" {{ $shell->attributes($style) }}>
@endforeach

@if (config('pwax.blade.head'))
    @include(config('pwax.blade.head'))
@endif
