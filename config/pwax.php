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
    | asset_ttl  max-age (seconds) for component .js / .css / .json responses. They
    |            are served `private` because a component can render differently per
    |            user, and always carry an ETag so a repeat request costs a 304.
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
    */

    'manifest_path' => '/manifest.webmanifest',

    'manifest' => [
        'name' => env('APP_NAME', 'Pwax App'),
        'short_name' => env('APP_NAME', 'Pwax'),
        'description' => null,
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'any',
        'background_color' => '#ffffff',
        'theme_color' => '#0c83ff',
        'icons' => [
            // [
            //     'src' => '/images/icons/icon-192.png',
            //     'sizes' => '192x192',
            //     'type' => 'image/png',
            //     'purpose' => 'any maskable',
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Service worker
    |--------------------------------------------------------------------------
    |
    | enabled     Register and serve a service worker.
    | path        URL it is served from. Keep it at the root so its scope covers the
    |             whole origin.
    | blade       Optional Blade view rendering the worker source. Publish the default
    |             with `--tag=pwax-service-worker`.
    | version     Bump to force every client to discard its caches on next visit.
    | precache    URLs cached at install time so the app shell works offline.
    | strategy    'network-first' favours freshness; 'stale-while-revalidate' favours
    |             speed and serves the cached copy while refreshing in the background.
    | offline_url Page shown when a navigation fails and nothing is cached.
    |
    */

    'service_worker' => [
        'enabled' => false,
        'path' => '/service-worker.js',
        'blade' => null,
        'version' => 'v1',
        'cache_name' => 'pwax',
        'precache' => ['/'],
        'strategy' => 'network-first',
        'offline_url' => null,
        'max_entries' => 60,
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
