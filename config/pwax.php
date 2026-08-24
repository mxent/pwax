<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | hash_route   Use hash history (#/path) instead of the History API. Only turn
    |              this on if your host cannot rewrite unknown paths to index.php.
    | home         Named route used as the fallback target for `Pwax::route()`.
    | route_prefix URL prefix for the internal component endpoints.
    | shell        Blade view used as the SPA shell on a full page load.
    |
    */

    'hash_route' => false,

    'home' => 'index',

    'route_prefix' => '__pwax__',

    'shell' => 'pwax::layouts.shell',

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Components are Blade views: they can call auth(), read the session, and branch
    | on a policy. They therefore run through a real middleware stack. Pwax's own
    | middleware is appended automatically — it translates redirects and expired CSRF
    | tokens into something the client runtime can act on.
    |
    | `routes.static_middleware` applies to the runtime bundle, the manifest and the
    | service worker. These are identical for every visitor and never touch the session,
    | so `web` deliberately does not appear: adding it would start a session and set a
    | cookie on requests that have no use for either.
    |
    | It is not empty, though. Being outside `web` also means being outside anything that
    | would slow an unauthenticated caller down, and `/sw.json` is the expensive one —
    | each build walks public/, every view root and every route. The manifest is memoised
    | and builds under a lock, so a flood mostly meets a cache hit; the throttle is the
    | backstop for the window where it does not.
    |
    | The rate is deliberately generous. These files are hard-cached, so one visitor costs
    | about three requests on their first visit and none afterwards — but throttling is
    | per IP address, and an office, a school or a mobile carrier is many visitors behind
    | one. Too low a limit does not degrade anything gracefully: it 429s `/sw.js` and
    | leaves those people without an installable app at all.
    |
    */

    'middleware' => ['web'],

    'routes' => [
        'register' => true,
        'domain' => null,
        'static_middleware' => ['throttle:300,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Components
    |--------------------------------------------------------------------------
    |
    | directive  Name of the Blade directive used to import a component.
    |
    |            Do NOT set this to "import". Blade matches `@name` even without
    |            arguments, so a directive named `import` also captures the CSS
    |            at-rule `@import url("...")` inside any <style> block in the entire
    |            application and replaces it with JavaScript, silently corrupting
    |            stylesheets. The package refuses to boot with that name.
    |
    | allowed    Optional allowlist of view-name patterns (Str::is syntax) that may be
    |            served as components. Component identifiers are already signed with
    |            your APP_KEY, so this is defence in depth rather than the primary
    |            control. Leave empty to allow any view you explicitly reference.
    |
    | scoped_styles  Honour `<style scoped>` by rewriting each selector and stamping the
    |                template's elements to match, so a rule cannot reach outside the
    |                component it was written in.
    |
    */

    'components' => [
        'directive' => 'pwaxImport',
        'allowed' => [],
        'scoped_styles' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Blade overrides
    |--------------------------------------------------------------------------
    |
    | Point any of these at one of your own Blade views to replace the bundled
    | partial without publishing the whole view directory.
    |
    */

    'blade' => [
        'content' => null,
        'head' => null,
        'foot' => null,
        'error' => null,
        'loader' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend assets
    |--------------------------------------------------------------------------
    |
    | source  'local' serves Vue, Vue Router and Pinia from your own origin;
    |         'cdn' loads them from the configured CDN with subresource integrity.
    |
    | A progressive web app that fetches its framework from a third-party CDN cannot
    | work offline — which is the entire point of a PWA — and discloses every
    | visitor's IP address to that CDN. 'local' is the default for both reasons.
    |
    | Publish the local copies with:
    |     php artisan vendor:publish --tag=pwax-assets
    |
    | Pwax compiles templates in the browser by default, so it serves the *full* Vue
    | build. See `vue_build` below for the opt-in alternative.
    |
    */

    'assets' => [
        // 'local' or 'cdn'. Named `source` because that is what it chooses — where the
        // framework is served from, not how anything is cached. The four keys named
        // `strategy` elsewhere in this file all choose a caching behaviour; this one does
        // not, so it is not called that.
        'source' => 'local',

        /*
        | 'full' or 'runtime'.
        |
        | 'full' ships Vue's template compiler, because compiling templates in the browser
        | is what lets this package have no build step. It costs about 20 kB gzipped over
        | the runtime-only build, and it is why `script-src 'unsafe-eval'` is required.
        |
        | 'runtime' is the opt-in trade in the other direction: run
        | `php artisan pwax:compile` after each deploy, ship 40.6 kB gzipped instead of
        | 60.7, and drop 'unsafe-eval'. It needs Node in your build, and
        | `@vue/compiler-dom` as a dev dependency at the version pinned below.
        |
        | Never having compiled is not an outage: an empty or missing store makes Pwax
        | serve the full build, and `pwax:doctor` reports it as an error so a silent
        | fallback is not a regression nobody can find. Compiling once and then changing a
        | component *is* an outage for that component, which is the other thing
        | `pwax:doctor` checks — put `pwax:compile` in your deploy, not in your memory.
        |
        | One constraint comes with it: a template must be the same for every visitor.
        | Keep controller data in <script> (`@json($user)`) and out of <template>, which
        | is the idiomatic split anyway. `pwax:compile` names any view that breaks it.
        */
        'vue_build' => 'full',

        // Where `pwax:compile` writes, and the Node binary it runs. Defaults are
        // storage/app/pwax/render-functions.php and whatever `node` resolves to.
        'render_functions' => null,
        'node' => null,

        'local_path' => '/vendor/pwax',

        'versions' => [
            'vue' => '3.5.41',
            'vue-router' => '5.2.0',
            'pinia' => '4.0.2',
        ],

        'cdn' => [
            'base' => 'https://cdn.jsdelivr.net/npm',

            // Subresource integrity for the exact builds pinned above. If you change a
            // version, regenerate these or the browser will refuse to run the script:
            //
            //   curl -sL <url> | openssl dgst -sha384 -binary | openssl base64 -A
            //
            // Keyed by package name, or by filename where a package ships more than one
            // build Pwax can serve. The filename wins.
            'integrity' => [
                'vue' => 'sha384-arPHRzOKPl8g3Rbe/cQBWYPnq4HcxfPFSFWD3qvI/hc2XQf+4GkVqkOlWgjN5mD3',
                'vue.runtime.global.prod.js' => 'sha384-RFxxAeahncPwNwUDUMprS/CVNUxKm7t0wLbqf3HZ+i5rvu2/QS+xB4Lo+eDZ75Fb',
                'vue-router' => 'sha384-bPPzCqx4xLwbRx+Dz7Wg1pyZ2CoP5XkRxCR5yfuA/U/QNsKJ0G7zkbuqzLyQLDSR',
                'pinia' => 'sha384-wg8sN8T2ZcZIv5vtyNApjm6zSpZ61ZgJEm5w3TXD7cGzWOhnNcNQkwvK39KIH5tp',
            ],
        ],

        // Load Pinia at all. Turn off if your app does not use a store.
        'pinia' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra global styles and scripts
    |--------------------------------------------------------------------------
    |
    | Each entry is either a URL string or an array of tag attributes:
    |
    |     'scripts' => [
    |         'https://example.com/a.js',
    |         ['src' => 'https://example.com/b.js', 'integrity' => 'sha384-…',
    |          'crossorigin' => 'anonymous', 'defer' => true],
    |     ],
    |
    | Stylesheets go in <head>. Scripts go at the end of <body>, after Vue, Vue Router
    | and Pinia, which is right for almost everything: a script there cannot hold up the
    | first paint. `head => true` moves one into <head> instead, for the two kinds that
    | have to run before the first paint and cannot wait behind the framework:
    |
    |     'scripts' => [
    |         // A CSS engine that builds its stylesheet by reading the DOM.
    |         ['src' => 'https://cdn.tailwindcss.com', 'head' => true],
    |         // A script that sets a theme class, to prevent a flash of the wrong one.
    |         ['src' => '/js/theme.js', 'head' => true],
    |     ],
    |
    | `head` is a placement instruction, not an attribute, and is not rendered as one.
    | Everything in <head> is render-blocking, so put nothing there that does not have
    | to be.
    |
    | Building your CSS to a file and listing it in `styles` beats an engine that runs at
    | request time: the file is fetched in parallel with the framework and cached by the
    | service worker, where an engine has to download, parse, read the DOM and write a
    | stylesheet before the page is styled — on every visit, offline included.
    |
    */

    'styles' => [],

    'scripts' => [],

    /*
    |--------------------------------------------------------------------------
    | Vue
    |--------------------------------------------------------------------------
    |
    | All client-side Vue configuration. Each entry is keyed by name; the value
    | is either an "@pwaxImport('view.name')" string referencing a component that
    | default-exports one, or a dotted path to look up on `window`.
    |
    |     'plugins'    => ['toast' => "@pwaxImport('plugins.toast')"],
    |     'directives' => ['focus' => "@pwaxImport('directives.focus')"],
    |     'middleware' => ['admin' => "@pwaxImport('middleware.admin-guard')"],
    |     'plugins'    => ['store' => 'MyLibrary.storePlugin'],
    |
    | The whole group lives under `vue` so the server-side `middleware` config
    | above (Laravel middleware groups) and the client-side `middleware`
    | (Vue route middleware) keep their natural names and never collide.
    |
    | Neither form is ever evaluated as code: a component reference is imported
    | from its own URL, and a dotted path is walked on `window` one segment at a
    | time. They are still configuration, and still not a place for user input —
    | a path that reaches an attacker's object is an attacker's plugin.
    |
    */

    'vue' => [
        'plugins' => [],
        'directives' => [],
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Minification
    |--------------------------------------------------------------------------
    |
    | enabled  Defaults to on in production only. Readable sources are worth far more
    |          than a few kilobytes while developing.
    | store    Cache store for minified output, keyed by a digest of the source, so a
    |          component is minified once rather than on every request. `null` uses
    |          the application default.
    | ttl      Seconds to keep entries, or null to keep them forever.
    |
    | Set `enabled` to false if your web server already applies gzip or brotli — that
    | recovers most of the same bytes with no per-request CPU cost.
    |
    */

    'minify' => [
        'enabled' => env('PWAX_MINIFY', null),
        'store' => null,
        'ttl' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | asset_ttl  max-age (seconds) for component module responses. They are served
    |            `private` because a component can render differently per user.
    |
    |            Note what `max-age` means: for that long the browser does not ask
    |            again at all, so the ETag these carry is not consulted and an edited
    |            component keeps serving its previous body. The component URL is
    |            derived from the view name, not its contents, so nothing else busts
    |            it either. With the service worker on this does not arise — the
    |            precache is content-addressed and answers before the HTTP cache is
    |            reached — but with it off, lower this in development.
    | components Cache compiled components. The cache key is a digest of the rendered
    |            output, so entries can never go stale — a changed component simply
    |            produces a new key.
    |
    |            What is cached is the *parse*: splitting the blocks, scoping the
    |            styles, stamping the template, minifying. Never the Blade render —
    |            that has to run on every request, because rendering is where a page's
    |            controller data enters.
    |
    |            Only renders that take no data are stored. A page rendered with
    |            controller data produces different output for every visitor, so every
    |            entry would be written once and read never; keying on the output is
    |            what makes the cache correct, and it is also what makes storing those
    |            renders pure growth. `->cacheable()` opts a page back in — a page whose
    |            payload is safe for a shared HTTP cache has already declared that it
    |            renders the same for everyone.
    | ttl        How long a stored component lives. Insurance rather than correctness:
    |            entries are content-keyed and can never be wrong, but a view that takes
    |            no data can still branch on `auth()` internally, and nothing else ever
    |            evicts those. Re-parsing a hot component once a week costs nothing.
    |            `null` keeps them forever.
    |
    */

    'cache' => [
        'asset_ttl' => 3600,
        'components' => true,
        'ttl' => 604800,
        'store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | nonce  A nonce (or a callable returning one) applied to the inline <style> and
    |        JSON blocks Pwax emits. Integrate with your CSP middleware of choice.
    |
    | Vue compiles templates in the browser using the Function constructor, so a
    | policy that omits `script-src 'unsafe-eval'` will break rendering. See the
    | Security section of the README for a complete, working policy.
    |
    */

    'csp' => [
        'nonce' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security headers
    |--------------------------------------------------------------------------
    |
    | Headers applied to every response Pwax serves — the page a visitor loads, the JSON
    | payload a client-side navigation fetches, the runtime bundle, the source maps, the
    | manifests, the offline shell, each per-component module.
    |
    | Every one of them, deliberately. Hardening only the package's own endpoints would
    | leave the application's real pages — the ones every visitor loads — carrying none of
    | this, and would let the two disagree: the same navigation cross-origin isolated when
    | the service worker answers it from the shell and not when the server answers it,
    | which is how an avatar that loads online gets refused offline.
    |
    | A document and an asset get different sets. `X-Content-Type-Options` and
    | `Referrer-Policy` are on everything; framing, permissions and the cross-origin
    | policies are only meaningful for something a browser renders as a page.
    |
    | Every value is overridable. Set any of them to `null` or `''` to drop the
    | corresponding header, and set one to a value of your own to replace it.
    |
    |   referrer_policy   `Referrer-Policy`. `no-referrer` is the strictest sane default:
    |                     a `Referer` sent to a third party the page happens to load an
    |                     asset from leaks the URL the visitor is on.
    |   frame_options     `X-Frame-Options` for documents. `SAMEORIGIN` lets the
    |                     application frame itself; `DENY` blocks that too.
    |   permissions_policy `Permissions-Policy` for documents.
    |
    |                     The default denies everything that reaches hardware, a sensor
    |                     or another origin's data, and allows the document its own use
    |                     of the features a progressive web app is built on — Web Share,
    |                     the wake lock, fullscreen, the clipboard, passkeys.
    |
    |                     That split matters. This used to deny every feature by name,
    |                     including four this package ships an API for, so
    |                     `window.pwax.share()` rejected on any navigation the worker
    |                     answered and nothing said why.
    |
    |                     Written as a comma-separated list of `feature=value` pairs,
    |                     where `()` denies, `(self)` allows this origin and `*` allows
    |                     any. Only features browsers implement are listed: an
    |                     unrecognised name costs a console warning on every page load.
    |   cross_origin_opener_policy  `Cross-Origin-Opener-Policy` for documents.
    |
    |                     `same-origin-allow-popups` severs the `window.opener` reference
    |                     a cross-origin page would hold, while leaving popups this
    |                     document opens able to talk back — which is how an OAuth flow
    |                     returns its result. `same-origin` severs both and is what to set
    |                     if you want cross-origin isolation.
    |   cross_origin_embedder_policy `Cross-Origin-Embedder-Policy` for documents. Off.
    |
    |                     `require-corp` refuses every cross-origin subresource that does
    |                     not carry `Cross-Origin-Resource-Policy` — an avatar from a
    |                     bucket, a font from a CDN, an embedded video. Paired with
    |                     `cross_origin_opener_policy => 'same-origin'` it buys
    |                     `SharedArrayBuffer` and high-resolution timers. Turn it on when
    |                     you want those, not before; `pwax:doctor` then checks that every
    |                     cross-origin script and stylesheet carries `crossorigin`.
    |
    */

    'security' => [
        'referrer_policy' => 'no-referrer',
        'frame_options' => 'SAMEORIGIN',
        'permissions_policy' => 'accelerometer=(), ambient-light-sensor=(), battery=(), '
            . 'bluetooth=(), camera=(), display-capture=(), document-domain=(), encrypted-media=(), '
            . 'gamepad=(), geolocation=(), gyroscope=(), hid=(), idle-detection=(), local-fonts=(), '
            . 'magnetometer=(), microphone=(), midi=(), payment=(), serial=(), sync-xhr=(), usb=(), '
            . 'window-management=(), xr-spatial-tracking=(), '
            . 'autoplay=(self), clipboard-read=(self), clipboard-write=(self), fullscreen=(self), '
            . 'picture-in-picture=(self), publickey-credentials-create=(self), '
            . 'publickey-credentials-get=(self), screen-wake-lock=(self), storage-access=(self), '
            . 'web-share=(self)',
        'cross_origin_opener_policy' => 'same-origin-allow-popups',
        'cross_origin_embedder_policy' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Visual customization
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Navigation feedback
    |--------------------------------------------------------------------------
    |
    | A client-side navigation has no address-bar spinner, so without something here a
    | slow page gives no sign that anything is happening. The progress bar is that sign,
    | and it is deliberately the only thing that moves: the page you are on stays
    | rendered until its replacement is ready, then the two cross-fade.
    |
    | Navigations only. The first load is covered by `customization.init_spinner` below —
    | a document arriving is a different wait, and the browser is already indicating it.
    |
    |   enabled   Set to false to remove the bar entirely — no element, no timers.
    |   color     Defaults to `customization.init_spinner_color`, so an application that
    |             set one has already set the other.
    |   height    Pixels.
    |   delay     Milliseconds of silence before the bar appears at all. Most navigations
    |             finish well inside this, and a bar that flashes on and off for every
    |             one of them reads as jitter rather than as feedback.
    |   trickle   Ease towards a ceiling while waiting. Off means the bar appears and
    |             then sits still until the page arrives.
    |
    | `window.pwax.progress` exposes `start()` and `done()` so the same indicator can
    | cover your own long-running work — a form submission, a report.
    |
    */

    'progress' => [
        'enabled' => true,
        'color' => null,
        'height' => 3,
        'delay' => 250,
        'trickle' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prefetching
    |--------------------------------------------------------------------------
    |
    | Fetch a page just before it is asked for. A visitor tells you where they are going
    | before they go — the pointer lands on a link a few hundred milliseconds before the
    | click, and a keyboard user focuses it first — and spending that time on the request
    | is what makes a navigation feel instant. It is the same request, sent earlier.
    |
    | 'hover'  fetch on pointer or focus, after `delay` ms of intent (default)
    | false    off
    |
    | Payloads are held in memory only, never written to disk, capped at eight and dropped
    | after thirty seconds. A page payload can carry a signed-in visitor's data, so a
    | prefetch is a head start rather than a cache — the service worker is what stores
    | pages, with rules about it.
    |
    | Costs a request for a link somebody hovers and does not click. Turn it off for an
    | application with expensive pages or metered users.
    |
    */

    'prefetch' => [
        'mode' => 'hover',
        'delay' => 65,
    ],

    /*
    |--------------------------------------------------------------------------
    | Page transition
    |--------------------------------------------------------------------------
    |
    | How long the cross-fade between pages runs for. The bundled `pwax-page` fades with
    | opacity alone — anything that changes an element's size or position is a second
    | kind of movement to follow, and the reason this exists is that navigation felt
    | unsettled.
    |
    | `duration` must agree with whatever the CSS does; it is what the default stylesheet
    | is written with. `0` is an instant swap; the browser still calls
    | `document.startViewTransition`, but the cross-fade collapses to nothing. Ignored
    | under `prefers-reduced-motion`.
    |
    */

    'transition' => [
        'duration' => 150,
    ],

    'customization' => [
        /*
        | The centred spinner covering the very first load, from the document arriving to
        | the runtime mounting.
        |
        | That wait is the browser's own; the progress bar has no part in it and belongs
        | to navigations, where nothing else would say a page is on its way. Turn this off
        | for an application that renders its own skeleton into the mount element instead.
        */
        'init_spinner' => true,
        'init_spinner_color' => '#0c83ff',
        'init_spinner_bg' => '#f3f3f3',
        'init_background' => '#ffffff',
    ],

    /*
    |--------------------------------------------------------------------------
    | PWA manifest
    |--------------------------------------------------------------------------
    |
    | Served at `manifest_path`. Fields follow the Web App Manifest specification:
    | https://developer.mozilla.org/docs/Web/Manifest
    |
    | Installability requires at least a 192x192 and a 512x512 icon. Run
    | `php artisan pwax:doctor` to check the manifest against that requirement.
    |
    | Every key below is emitted verbatim, so any member the specification gains can
    | simply be added here. Empty values (null, '', []) are dropped; `false` and `0`
    | are kept, because `prefer_related_applications => false` is meaningful.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Web Push
    |--------------------------------------------------------------------------
    |
    | Pwax handles the browser half of push: asking permission, subscribing with your
    | VAPID key, handing the subscription to your endpoint, and showing the notification
    | in the worker.
    |
    | It deliberately does not handle the server half. Storing subscriptions and sending
    | to them is what `laravel-notification-channels/webpush` does, it does it well, and a
    | second implementation inside a PWA package would be a worse one. Install it, point
    | `endpoint` at a route that persists what the browser posts, and the two halves meet.
    |
    | public_key   Your VAPID public key. Without it `pwax.push.subscribe()` does nothing.
    | private_key  Your VAPID private key. Pwax does not use it — it is the browser half
    |              only — but `pwax:doctor` validates its shape so a typo is caught here
    |              rather than as a 401 from the push service. The application's own
    |              push-sending code reads this; Pwax itself never does.
    | endpoint     A route of yours. It receives POST with the PushSubscription as JSON
    |              when someone subscribes, and DELETE with the same when they leave.
    |              It must be on this origin — a path, not an absolute URL elsewhere.
    |              The runtime posts to it with the session's CSRF token attached, so a
    |              cross-origin value would hand that token to another origin; it is
    |              refused and logged rather than sent. A non-2xx answer, or an endpoint
    |              that cannot be reached, is logged too: the browser is then subscribed
    |              and the server does not know it exists, which looks exactly like a bad
    |              VAPID key and is not one.
    | title/icon   Fallbacks for a push whose payload omits them. Every browser that
    |              implements push requires a notification to be shown for every message,
    |              so a payload that says nothing must still produce something.
    |
    | Nothing is asked of the visitor automatically. `subscribe()` must be called from a
    | user gesture, because browsers reject permission requests that are not — and a page
    | that asks on load is the reason they do.
    |
    */

    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'endpoint' => null,
        'title' => null,
        'icon' => null,
        'badge' => null,
    ],

    'manifest_path' => '/manifest.json',

    /*
    |--------------------------------------------------------------------------
    | Document head
    |--------------------------------------------------------------------------
    |
    | What goes in <head>, in a fixed order so that the markup is predictable whichever
    | page rendered it.
    |
    | title            Falls back to `manifest.name`. Override per page with
    |                  pwaxRender('pages.about')->title('About us').
    | title_template   Applied only when a page supplied its own title, e.g.
    |                  ':title · Acme' renders 'About us · Acme'.
    | description      Falls back to `manifest.description`.
    | icon             <link rel="icon">. Defaults to the smallest square manifest icon
    |                  of at least 32px, then /favicon.ico if that file exists.
    | base             <base href>. Leave null unless you know you need it: it changes
    |                  how every relative URL in the document resolves, including the
    |                  src of every image in every component. Routing does not need it —
    |                  a subdirectory install is already handled by the runtime.
    | color_scheme     <meta name="color-scheme">, e.g. 'light dark'.
    | theme_color_dark Theme colour for a dark colour scheme, if it differs.
    | open_graph       Derive Open Graph and Twitter card tags from the title, the
    |                  description and the canonical URL. Nothing is invented: a tag is
    |                  emitted only where a value for it already exists, and a page that
    |                  set one by hand keeps its own.
    | open_graph_type  `og:type`. 'website' unless your app is something else.
    | twitter_card     `twitter:card`. Left null it follows the image: a card declaring
    |                  'summary_large_image' with no image renders as a bare 'summary'
    |                  anyway, and a 'summary' alongside a 1200x630 image throws most of
    |                  that artwork away. Set it to pin one spelling for every page.
    | image            The social sharing card for pages that name none of their own —
    |                  `og:image` and `twitter:image`. A site-relative path is made
    |                  absolute: a scraper reading Open Graph does not necessarily have
    |                  the document to resolve one against, and the failure is a link
    |                  preview with no image rather than an error. 1200x630 is the size
    |                  every platform crops well.
    | robots           The default `robots` directive for every page that does not set
    |                  its own. This is where a staging deployment says 'noindex,
    |                  nofollow' once instead of on every route.
    | locale           `og:locale`, in Open Graph's underscored form. Defaults to the
    |                  application locale, so a localised app declares this once.
    | alternates       `rel="alternate"` links for every page, as
    |                  ['en' => 'https://example.com', 'fr' => 'https://example.com/fr'].
    |                  Use 'x-default' for the fallback. A localised page that declares
    |                  none is competing with its own translations in the index.
    | json_ld          Structured data for every page that declares none — normally the
    |                  site's own identity, an Organization or a WebSite. One array, or
    |                  a list of them. A page that calls `->jsonLd()` replaces this
    |                  rather than adding to it: an Article and an Organization are two
    |                  claims about two different things, and emitting both against one
    |                  URL says the page is both.
    |
    | Per page, on the response:
    |
    |     pwaxRender('pages.post', [...])
    |         ->title($post->title)
    |         ->description($post->excerpt)
    |         ->canonical(route('posts.show', $post))
    |         ->image($post->cover_url)
    |         ->robots($post->draft ? 'noindex' : 'index, follow')
    |         ->alternate('fr', route('posts.show', [$post, 'locale' => 'fr']))
    |         ->jsonLd([
    |             '@context' => 'https://schema.org',
    |             '@type' => 'Article',
    |             'headline' => $post->title,
    |             'datePublished' => $post->published_at->toIso8601String(),
    |         ]);
    |
    | Those travel in the payload as well as the document, so a client-side navigation
    | updates them too. A browser replaces the head on a real navigation and a router
    | does not — a title that moves with the route and a description that does not is
    | worse than setting neither.
    |
    | This does NOT make the application crawlable. Page content is compiled in the
    | browser from a JSON island; a crawler that does not run JavaScript sees the shell.
    | These tags are for the ones that do, and for link unfurling.
    |
    */

    'head' => [
        'title' => null,
        'title_template' => null,
        'description' => null,
        'icon' => null,
        'base' => null,
        'color_scheme' => null,
        'theme_color_dark' => null,
        'open_graph' => true,
        'open_graph_type' => 'website',
        'twitter_card' => null,
        'image' => null,
        'robots' => null,
        'locale' => null,
        'alternates' => [],
        'json_ld' => null,
    ],

    'manifest' => [
        // A stable identity for the installed app, independent of `start_url`. Without
        // it a browser identifies the installation by `start_url`, so changing that
        // orphans every existing install and creates a second one. Defaults to
        // `start_url`; set it explicitly and never change it again.
        'id' => null,

        'name' => env('APP_NAME', 'Pwax App'),
        'short_name' => env('APP_NAME', 'Pwax'),
        'description' => null,

        // Defaults to the application locale when null.
        'lang' => null,
        'dir' => 'auto',

        'start_url' => '/',
        'scope' => '/',

        'display' => 'standalone',

        // Tried in order before falling back to `display`. 'window-controls-overlay'
        // and 'tabbed' are ignored by browsers that do not implement them.
        'display_override' => [],

        'orientation' => 'any',
        'background_color' => '#ffffff',
        'theme_color' => '#0c83ff',

        'categories' => [],

        'icons' => [
            // [
            //     'src' => '/images/icons/icon-192.png',
            //     'sizes' => '192x192',
            //     'type' => 'image/png',
            //     'purpose' => 'any',
            // ],
            // A maskable icon should be a separate entry: 'any maskable' asks one image
            // to satisfy two incompatible safe zones and is padded wrongly in one of them.
            // [
            //     'src' => '/images/icons/maskable-512.png',
            //     'sizes' => '512x512',
            //     'type' => 'image/png',
            //     'purpose' => 'maskable',
            // ],
        ],

        // Required by Chromium for a richer install dialogue on desktop and Android.
        'screenshots' => [
            // ['src' => '/images/screens/wide.png', 'sizes' => '1280x720',
            //  'type' => 'image/png', 'form_factor' => 'wide', 'label' => 'Dashboard'],
        ],

        'shortcuts' => [
            // ['name' => 'New invoice', 'url' => '/invoices/create',
            //  'icons' => [['src' => '/images/icons/new-96.png', 'sizes' => '96x96']]],
        ],

        // ['client_mode' => 'navigate-existing'] focuses the running window instead of
        // opening a second one when the app is launched again.
        'launch_handler' => null,

        'handle_links' => null,

        /*
        | The three members that hand your application an entry point from outside the
        | browser. Each names a URL the operating system will open the app at, and the
        | route behind it is yours to write — the manifest makes the promise, your
        | application keeps it. `php artisan pwax:doctor` resolves every one of them
        | against the real route table, with the method the browser will use.
        |
        | A file handler and a protocol handler are delivered through the launch queue,
        | which the runtime consumes: `window.pwax.launch.consume(fn)`. A POST share
        | target is an ordinary form POST from outside your app, so its route needs CSRF
        | exemption and its own validation.
        |
        |   'protocol_handlers' => [
        |       ['protocol' => 'web+invoice', 'url' => '/invoices/open?ref=%s'],
        |   ],
        |   'file_handlers' => [
        |       ['action' => '/import', 'accept' => ['text/csv' => ['.csv']]],
        |   ],
        |   'share_target' => [
        |       'action' => '/share',
        |       'method' => 'POST',
        |       'enctype' => 'multipart/form-data',
        |       'params' => ['title' => 'title', 'text' => 'text', 'url' => 'url'],
        |   ],
        */
        'protocol_handlers' => [],
        'file_handlers' => [],
        'share_target' => null,
        'scope_extensions' => [],
        'edge_side_panel' => null,
        'iarc_rating_id' => null,
        'related_applications' => [],
        'prefer_related_applications' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Service worker
    |--------------------------------------------------------------------------
    |
    | The worker is driven by an asset manifest — `sw.json`. The manifest lists every URL
    | the application is made of together with a content hash, so the worker precaches
    | the whole app at
    | install time and busts individual entries when their hash changes. There is no
    | "visit the page once to cache it" step, and no manual version bump needed to ship
    | a change.
    |
    | enabled     Register and serve a service worker.
    | path        URL it is served from. Keep it at the root so its scope covers the
    |             whole origin.
    | scope       Scope passed to `navigator.serviceWorker.register`.
    | blade       Optional Blade view replacing the worker source outright. See the note
    |             on the key itself below.
    | version     Mixed into the manifest hash. Bump it to force every client to discard
    |             its caches even when no file changed.
    | offline_url Page shown when a navigation fails. Defaults to the app shell.
    |
    */

    'service_worker' => [
        'enabled' => false,
        'path' => '/sw.js',
        'scope' => '/',
        /*
        | Add behaviour to the worker without forking it.
        |
        | Each entry is a view name or an absolute path; the contents are appended after
        | the worker and share its scope, so `CONFIG`, `PREFIX` and the cache helpers are
        | all in reach. This is where a `push` handler, a `sync` handler or anything else
        | the package does not ship belongs — rather than publishing 1,600 lines to add
        | ten and inheriting every future fix by hand.
        |
        |     'extend' => ['js.push-handler'],
        */
        'extend' => [],

        /*
        | The document a navigation gets with no network and nothing stored for the URL.
        | A Blade view, so it carries the application's language and direction. Publish
        | it with `vendor:publish --tag=pwax-service-worker`.
        */
        'offline_view' => null,

        /*
        | Replace the worker outright with a Blade view of your own.
        |
        | Supported, but `extend` above is almost always what you want: a worker written
        | from nothing owns every future fix to caching, updates and push by hand.
        */
        'blade' => null,

        'version' => 'v1',
        'cache_name' => 'pwax',
        'offline_url' => null,
        'navigation_preload' => true,

        /*
        | What happens to a same-origin GET that nothing in the manifest claims.
        |
        | 'network-only'            pass it through and store nothing (default)
        | 'network-first'           store a copy, and serve that copy when offline
        | 'stale-while-revalidate'  serve the copy first and refresh behind it
        |
        | No 'cache-first' here, deliberately. This governs URLs the application never
        | declared, and serving one from disk in preference to the network means serving
        | it stale forever — there is no hash to notice it changed. A URL that should be
        | answered from cache first belongs in an asset group, where it is listed and
        | content-addressed.
        |
        | The default is the conservative one because the alternative kept everything: a
        | one-off PDF, a CSV export, a file under /storage — URLs the application never
        | declared, taking up someone's disk and never asked for offline. What an
        | application genuinely needs offline belongs in an asset group or a data group,
        | where it is listed, hashed and bounded.
        |
        | This governs the runtime cache only. Anything in the manifest is precached, and
        | anything under Pwax's own prefixes is served from cache and revalidated,
        | whatever this says.
        */
        'runtime_strategy' => 'network-only',

        /*
        | Ceilings on the runtime cache.
        |
        | `max_entries` counts entries and `max_entry_bytes` bounds each one, because the
        | first without the second is not a bound on anything: sixty JSON payloads and
        | sixty videos are very different amounts of a visitor's disk, and one large
        | response can push the origin over its quota and have the browser evict the
        | precache — taking the application's offline capability with it.
        |
        | Precached entries are never evicted by either, so ordinary browsing cannot push
        | the app shell out of storage.
        |
        | A response with no Content-Length is kept: measuring it would mean buffering the
        | very responses the ceiling exists to avoid buffering.
        */
        'max_entries' => 60,
        'max_entry_bytes' => 5242880,

        /*
        | How a full page navigation is answered.
        |
        | 'network-first'  Go to the network, fall back to the precached shell. Safe
        |                  alongside any server-rendered route inside the worker's scope.
        | 'cache-first'    Serve the precached shell immediately, with no network wait,
        |                  and let the runtime fetch the page payload. Much faster, but
        |                  every navigation this worker claims becomes the SPA — check
        |                  `navigation_urls` first if Horizon, Telescope, Nova or a
        |                  Filament panel share this domain.
        |
        | One vocabulary across every strategy key in this file — `runtime_strategy`,
        | this one, `pages.strategy` and each data group's. An unrecognised value falls
        | back to the default and `pwax:doctor` fails on it.
        */
        'navigation_strategy' => 'network-first',

        /*
        | Which navigations belong to the application. A path matched by none of these,
        | or excluded by a leading !, bypasses the worker entirely and goes straight to
        | the network.
        |
        | The defaults claim everything except paths containing a file extension or a
        | double underscore — so /reports/2024.pdf and /admin/__debug are left alone.
        */
        'navigation_urls' => ['/**', '!/**/*.*', '!/**/*__*', '!/**/*__*/**'],

        /*
        | The asset manifest itself. `ttl` is how long the built manifest is memoised
        | server-side; building it scans the view directory. Set it to 0 to rebuild on
        | every request while developing.
        */
        'asset_manifest' => [
            'path' => '/sw.json',
            'ttl' => 60,
        ],

        /*
        | The offline app shell.
        |
        | This is the SPA shell rendered with no session and no page component: no CSRF
        | token, no controller data, identical for every visitor. It is what the worker
        | serves when a navigation cannot reach the network, and the client runtime
        | takes over routing from there.
        |
        | Application pages are precached too — see `pages` below — and the shell is what
        | answers a navigation to one that is not. Caches are shared across visitors, so
        | the page or document the worker serves is whatever the server returned for
        | the URL — there is no "anonymous-only" precache.
        */
        'shell' => [
            'enabled' => true,
            'path' => '/__pwax__/shell',
        ],

        /*
        | Precache Vue, Vue Router, Pinia, the client runtime and the web manifest.
        | Without this the framework is only cached after it has been fetched online at
        | least once, so a first visit followed by going offline shows nothing at all.
        */
        'assets' => true,

        /*
        | Which components to precache.
        |
        |     'all'                        every component Pwax can find (default)
        |     false                        none; they are cached lazily as they load
        |     ['components.*', 'ui.*']     only views matching these patterns
        |
        | Components are discovered by scanning the view paths for Blade files that
        | contain a `<template>` block or a `<script>` block with an `export`. Run
        | `php artisan pwax:precache` to see exactly what this resolves to.
        */
        'components' => 'all',

        // View-name patterns never precached, whatever `components` says.
        'exclude' => ['vendor.pwax.*'],

        // Extra directories to scan, beyond the ones registered with the view finder.
        'paths' => [],

        /*
        | Package view namespaces to scan as well, e.g. ['ui'] for `@pwaxImport('ui::button')`.
        |
        | Empty by default, and deliberately so. Every package that calls
        | `loadViewsFrom()` registers a namespace — Laravel's own exception page renderer
        | among them — and none of those views are components your application imports.
        | Scanning them fills the manifest with URLs that cannot render offline, and mints
        | a signed, publicly addressable URL for each one.
        */
        'namespaces' => [],

        /*
        |----------------------------------------------------------------------
        | Asset groups
        |----------------------------------------------------------------------
        |
        | `files` are globs resolved against your public/ directory and hashed from
        | disk, so changing one file busts exactly that entry. `urls` are literal and
        | may be cross-origin.
        |
        |   install_mode  'prefetch' fetches everything at install, so the application
        |                 works offline after a single visit. 'lazy' fetches on first
        |                 use and then keeps it, which suits a large media library
        |                 nobody looks at all of.
        |   update_mode   what happens on the next deploy to a lazy entry this device
        |                 already holds and whose hash changed. 'prefetch' brings it up
        |                 to date during the install, so nobody waits for it; 'lazy'
        |                 drops it and lets the next request fetch it. Files the device
        |                 never asked for are not fetched either way — that is what
        |                 install_mode above already decided. Prefetch groups fetch
        |                 everything at install regardless, so this does not apply.
        |
        | Glob syntax: ** crosses directory boundaries, * does not, ? is one character,
        | {a,b} and (a|b) alternate, and a leading ! excludes.
        |
        | public/storage is never walked — it is a symlink to user uploads, and
        | precaching whatever anyone has ever uploaded is not what this is for. .php
        | files, dotfiles and source maps are excluded too.
        */
        'asset_groups' => [
            [
                'name' => 'app',
                'install_mode' => 'prefetch',
                'update_mode' => 'prefetch',
                'files' => ['/favicon.ico', '/css/**.css', '/js/**.js', '/build/**'],
            ],
            [
                'name' => 'assets',
                'install_mode' => 'lazy',
                'update_mode' => 'prefetch',
                'files' => [
                    '/images/**',
                    '/fonts/**',
                    '/media/**',
                    '/**.(svg|png|jpg|jpeg|webp|avif|gif|ico|woff|woff2|ttf|otf|mp4|webm|mp3|ogg)',
                ],
            ],
        ],

        /*
        | Ceilings on what a glob may pull in.
        |
        | A stray '/**' on a media-heavy public/ should not try to precache two
        | gigabytes. On breach the manifest is truncated at a stable point — so it stays
        | byte-identical between builds — and the reason appears in the manifest's
        | `warnings`, in `php artisan pwax:precache`, and in the log. Truncating beats
        | throwing: a partial precache with a message is recoverable, a 500 on sw.json
        | takes the whole application's offline capability with it.
        */
        'max_files' => 2000,
        'max_bytes' => 67108864,

        // Source maps are legitimate, merely large and useless without devtools open.
        'source_maps' => false,

        // Extra glob patterns never precached, whatever the asset groups say.
        'exclude_files' => [],

        /*
        |----------------------------------------------------------------------
        | Pages
        |----------------------------------------------------------------------
        |
        | Application routes to make available offline.
        |
        | Each one is stored twice: the JSON payload the runtime asks for when routing
        | client-side, and the rendered HTML a navigation gets — the document with the
        | component already inlined in its `pwax-initial` island. That second copy is
        | why an offline navigation paints the page immediately instead of showing the
        | shell's spinner while the runtime fetches a payload it already has.
        |
        | They are cached normally, with cookies if the request carries them. Caches
        | are shared across visitors, so the page the worker stores is the one the
        | server returned — whatever that is. A route behind auth that answers a
        | signed-out navigation with a login screen is detected and refused (a payload
        | is JSON; a login screen is not), so the page never ends up as a login page.
        |
        | Those copies are shared: every visitor gets the same ones, and a visitor's own
        | cache is preferred when it has the page. That is what makes an offline link
        | to a page you have not opened this session work at all — it is the same
        | content a reload of that URL is already answered with.
        |
        | `discover` finds them for you. Every GET route whose action hands a literal
        | view name to pwaxRender() is precached, so installing from the home page and
        | then opening Settings offline works without either page having been visited.
        | Scoped by `components` above: 'all' takes every page, ['pages.*'] takes only
        | the ones whose view matches — one setting governs pages and components alike.
        |
        | A route that computes its view name, or renders through a service, cannot be
        | read statically and belongs in `urls`. So does a parameterised route, which
        | is a template rather than a page. `php artisan pwax:precache` lists exactly
        | what was found.
        |
        | `runtime` caches pages as they are visited, which covers what discovery
        | cannot — a `/posts/{post}` someone actually opened. Caches are shared across
        | visitors: the page the server returned for the URL is what the next visitor
        | gets on this device. What the build installed is kept separately and
        | survives a deploy, so a deploy never re-downloads the application.
        |
        | A page's rendered HTML is kept too, so reloading such a route offline paints
        | it rather than a spinner.
        |
        | Between them these decide whether page content reaches disk at all. Turn
        | `runtime` off if none of it may; for a single page, ->offline(false) refuses
        | outright:
        |
        |     Route::get('/codes', fn () => pwaxRender('pages.codes')->offline(false));
        */
        'pages' => [
            'urls' => [],
            'discover' => true,
            'runtime' => true,

            /*
            | A page answers two ways: a JSON payload to the client runtime, and an HTML
            | document to a browser navigation. Both are precached.
            |
            | Precaching only the payload leaves a route the visitor has never opened
            | with no document at all. It still works offline, because the runtime boots
            | from the shell and finds the payload in the cache; it just paints a spinner
            | first and takes an extra hop to do what the server had already rendered.
            |
            | The cost is one extra request per page at install. `false` halves that, at
            | the price of that spinner on any cold start of an unvisited route. Note that
            | documents are stored as visited either way — this is about the install.
            |
            | A page that must not have its HTML on disk at all says so per route, with
            | `->offline(false)`, which is honoured here as everywhere else.
            */
            'documents' => true,

            /*
            | A view rendered as a page is precached as a page, not as an importable
            | module. The page payload carries its script inline, so the module URL is
            | never fetched to render that page — precaching it costs a request and, for
            | a view that needs controller data, fails with an error pointing at a URL
            | nothing would have asked for. Turn this on only if you also import a page
            | view into another component with @pwaxImport.
            */
            'as_components' => false,
            'strategy' => 'network-first',   // or 'cache-first'
            'timeout' => 2000,
            'max_entries' => 60,

            /*
            | Cookies are passed through by default. Caches are shared across visitors,
            | so what is stored is the response for whoever fetched the page last —
            | for a page that renders the same for everyone this is irrelevant, and for
            | a page that does not it is what `->offline(false)` exists to refuse.
            */
            'credentials' => 'include',
        ],

        /*
        |----------------------------------------------------------------------
        | Data groups
        |----------------------------------------------------------------------
        |
        | Runtime caching for API responses. Without them an offline page renders but
        | every fetch it makes fails.
        |
        |   network-first  go to the network, fall back to the cache after `timeout` ms
        |   cache-first    serve the cache while it is younger than `max_age`
        |
        | `version` names the group's cache. Bump it to discard what is stored for that
        | group and nothing else — after changing the shape of a response, say. Deploys
        | do not discard these on their own: an API cache is keyed by the group rather
        | than the build, so it survives a release the way the runtime cache does.
        |
        | SECURITY: these are responses, not files, and they can hold one person's data.
        | They are cached normally, and caches are shared across visitors — anyone with
        | the device sees the same API responses as the last user. Do not add an
        | authenticated endpoint here without deciding that is acceptable, or guarding
        | the response with `X-Pwax-Cache: none` server-side.
        */
        'data_groups' => [
            // [
            //     'name' => 'posts',
            //     'urls' => ['/api/posts', '/api/posts/**'],
            //     'version' => 1,
            //     'strategy' => 'network-first',
            //     'max_entries' => 50,
            //     'max_age' => 3600,
            //     'timeout' => 3000,
            // ],
        ],

    ],

];
