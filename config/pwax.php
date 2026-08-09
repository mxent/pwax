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
    | and now builds under a lock, so a flood mostly meets a cache hit; the throttle is
    | the backstop for the window where it does not.
    |
    */

    'middleware' => ['web'],

    'routes' => [
        'register' => true,
        'domain' => null,
        'static_middleware' => ['throttle:120,1'],
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
    |            application and replaces it with JavaScript. That was the behaviour
    |            in 1.x and it silently corrupted stylesheets.
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
    | strategy  'local' serves Vue, Vue Router and Pinia from your own origin;
    |           'cdn' loads them from the configured CDN with subresource integrity.
    |
    | A progressive web app that fetches its framework from a third-party CDN cannot
    | work offline — which is the entire point of a PWA — and discloses every
    | visitor's IP address to that CDN. 'local' is the default for both reasons.
    |
    | Publish the local copies with:
    |     php artisan vendor:publish --tag=pwax-assets
    |
    | NOTE: Pwax compiles templates in the browser, so it requires the *full* Vue
    | build (vue.global.prod.js). vue.runtime.global.prod.js will not work.
    |
    */

    'assets' => [
        'strategy' => 'local',

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
            'integrity' => [
                'vue' => 'sha384-arPHRzOKPl8g3Rbe/cQBWYPnq4HcxfPFSFWD3qvI/hc2XQf+4GkVqkOlWgjN5mD3',
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
    */

    'styles' => [],

    'scripts' => [],

    /*
    |--------------------------------------------------------------------------
    | Vue extensions
    |--------------------------------------------------------------------------
    |
    | Keyed by name. Each value is either a raw JavaScript expression or an
    | "@pwaxImport('view.name')" string referencing a component that default-exports one.
    |
    |     'plugins' => ['toast' => "@pwaxImport('plugins.toast')"],
    |
    | SECURITY: these values are emitted into the page as JavaScript. They are
    | configuration, never a place for user input.
    |
    */

    'plugins' => [],

    'directives' => [],

    'middleware_js' => [],

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
    |            `private` because a component can render differently per user, and
    |            always carry an ETag so a repeat request costs a 304.
    | components Cache compiled components. The cache key is a digest of the rendered
    |            output, so entries can never go stale — a changed component simply
    |            produces a new key.
    |
    */

    'cache' => [
        'asset_ttl' => 3600,
        'components' => true,
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
    | Page transition
    |--------------------------------------------------------------------------
    |
    | The name of the Vue transition wrapping the routed page, and how long its CSS runs
    | for. The bundled `pwax-page` fades, using opacity alone — anything that changes an
    | element's size or position is a second kind of movement to follow, and the reason
    | this exists is that navigation felt unsettled.
    |
    | `duration` must agree with whatever the CSS does; it is what the default stylesheet
    | is written with. Name your own transition here and define its classes in your own
    | stylesheet to replace it entirely. Both are ignored under `prefers-reduced-motion`.
    |
    */

    'transition' => [
        'name' => 'pwax-page',
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
    | blade       Optional Blade view rendering the worker source. Publish the default
    |             with `--tag=pwax-service-worker`.
    | version     Mixed into the manifest hash. Bump it to force every client to discard
    |             its caches even when no file changed.
    | offline_url Page shown when a navigation fails. Defaults to the app shell.
    |
    */

    'service_worker' => [
        'enabled' => false,
        'path' => '/sw.js',
        'scope' => '/',
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
        | 'app-shell'      Serve the precached shell immediately, with no network wait,
        |                  and let the runtime fetch the page payload. Much faster, but
        |                  every navigation this worker claims becomes the SPA — check
        |                  `navigation_urls` first if Horizon, Telescope, Nova or a
        |                  Filament panel share this domain.
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
        | Precaching real application URLs instead would store one authenticated user's
        | HTML on disk under a URL another user of the same device would then be served.
        | The shell has nothing in it to leak, which is what makes precaching it safe.
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
        |   update_mode   what happens on the next deploy to an entry whose hash
        |                 changed: fetch it eagerly, or wait until it is asked for.
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
        | They are fetched without cookies, so what is stored is the guest rendering.
        | A route behind auth answers that request with a login screen rather than a
        | payload, which is refused and reported — so listing one is harmless, it just
        | will not be there before sign-in.
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
        | cannot — a `/posts/{post}` someone actually opened. Those go in a cache named
        | for the signed-in identity, so one visitor's pages are unreachable from
        | another's session on the same device; see `identity_cache_limit` below.
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
            | A view rendered as a page is precached as a page, not as an importable
            | module. The page payload carries its script inline, so the module URL is
            | never fetched to render that page — precaching it costs a request and, for
            | a view that needs controller data, fails with an error pointing at a URL
            | nothing would have asked for. Turn this on only if you also import a page
            | view into another component with @pwaxImport.
            */
            'as_components' => false,
            'strategy' => 'freshness',   // 'freshness' | 'performance'
            'timeout' => 2000,
            'max_entries' => 60,

            /*
            | Fetched without cookies at install, so what is precached is the guest
            | rendering — which is what "renders the same for everyone" means. Set this
            | to 'include' only if the page genuinely needs the session, and understand
            | that it then stores whichever visitor triggered the install.
            */
            'credentials' => 'omit',
        ],

        /*
        |----------------------------------------------------------------------
        | Data groups
        |----------------------------------------------------------------------
        |
        | Runtime caching for API responses. Without them an offline page renders but
        | every fetch it makes fails.
        |
        |   freshness    go to the network, fall back to the cache after `timeout` ms
        |   performance  serve the cache while it is younger than `max_age`
        |
        | SECURITY: these are responses, not files, and they can hold one person's data.
        | They are stored per signed-in identity and `no-store` is still honoured, but a
        | device shared between two people is a device with both their data on it. Do not
        | add an authenticated endpoint here without deciding that is acceptable.
        |
        | Written flat, like `pages` and `asset_groups`: `max_entries` here is the same
        | quantity as `max_entries` there, and had no business being spelled differently
        | one level further down.
        */
        'data_groups' => [
            // [
            //     'name' => 'posts',
            //     'urls' => ['/api/posts', '/api/posts/**'],
            //     'version' => 1,
            //     'strategy' => 'freshness',
            //     'max_entries' => 50,
            //     'max_age' => 3600,
            //     'timeout' => 3000,
            // ],
        ],

        /*
        | How many signed-in identities keep caches on one device.
        |
        | Every person who signs in leaves a set behind. On a shared machine that grows
        | until the browser evicts storage on its own, which takes the application's
        | offline capability with it. Oldest go first.
        */
        'identity_cache_limit' => 2,
    ],

];
