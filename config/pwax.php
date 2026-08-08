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
    | service worker. These are identical for every visitor and never touch the
    | session, so the default is deliberately empty: adding `web` here would start a
    | session and set a cookie on requests that have no use for either.
    |
    */

    'middleware' => ['web'],

    'routes' => [
        'register' => true,
        'domain' => null,
        'static_middleware' => [],
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
    | scoped_styles  Honour `<style scoped>` by rewriting selectors and stamping the
    |                template, the way Vue's SFC compiler does at build time.
    |
    */

    'components' => [
        'directive' => 'pwax',
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
    | "@pwax('view.name')" string referencing a component that default-exports one.
    |
    |     'plugins' => ['toast' => "@pwax('plugins.toast')"],
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

    'customization' => [
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

    'manifest_path' => '/manifest.webmanifest',

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
    | The worker is driven by an asset manifest — `sw.json` — in the same way Angular's
    | worker is driven by `ngsw.json`. The manifest lists every URL the application is
    | made of together with a content hash, so the worker precaches the whole app at
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
    | strategy    Applies to same-origin requests that are not in the manifest.
    |             'network-first' favours freshness; 'stale-while-revalidate' favours
    |             speed and serves the cached copy while refreshing in the background.
    | offline_url Page shown when a navigation fails. Defaults to the app shell.
    | max_entries Cap on the *runtime* cache only. Precached entries are never evicted,
    |             so ordinary browsing can no longer push the app shell out of storage.
    |
    */

    'service_worker' => [
        'enabled' => false,
        'path' => '/service-worker.js',
        'scope' => '/',
        'blade' => null,
        'version' => 'v1',
        'cache_name' => 'pwax',
        'strategy' => 'network-first',
        'offline_url' => null,
        'max_entries' => 60,
        'navigation_preload' => true,

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
        | Additional URLs to precache.
        |
        | `files` are static files under `public/` — a logo, a font, a stylesheet — and
        | are hashed from disk, so changing one busts only that entry.
        |
        | `precache` are application routes. Use it only for pages that render the same
        | for everyone: the worker refuses to store any response the server marked
        | `no-store`, which is every page Pwax renders through `pwax_component()`.
        */
        'files' => [],

        'precache' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global helper functions
    |--------------------------------------------------------------------------
    |
    | Pwax's canonical API is the `Pwax` facade. Setting this to true additionally
    | defines the bare `vue()` and `router()` helpers from 1.x. They occupy very
    | common names in the global namespace, so they are opt-in.
    |
    */

    'helpers' => [
        'global' => false,
    ],

];
