<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    | hash_route   - Use hash-based history (#/path) when true.
    | home         - Route name used as fallback / "return home" link.
    | route_prefix - Prefix for internal Pwax module/js/css routes.
    */
    'hash_route' => false,
    'home' => 'index',
    'route_prefix' => '__pwax__',

    /*
    |--------------------------------------------------------------------------
    | Blade overrides
    |--------------------------------------------------------------------------
    | Set to a Blade view name to override the default partial.
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
    | Visual customization
    |--------------------------------------------------------------------------
    */
    'customization' => [
        'init_spinner_color' => '#0c83ff',
        'init_spinner_bg' => '#f3f3f3',
    ],

    /*
    |--------------------------------------------------------------------------
    | Global stylesheets / scripts injected into the layout
    |--------------------------------------------------------------------------
    */
    'styles' => [],

    'scripts' => [
        'https://unpkg.com/vue@3.5.18/dist/vue.global.prod.js',
        'https://unpkg.com/vue-router@4.5.1/dist/vue-router.global.prod.js',
        'https://unpkg.com/pinia@3.0.3/dist/pinia.iife.prod.js',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vue extensions
    |--------------------------------------------------------------------------
    | Each item is keyed by a name and given either:
    |   - a raw JS expression (e.g. 'MyPlugin.default'); or
    |   - an "@import('view.name')" string referencing a Pwax module.
    */
    'plugins' => [],
    'directives' => [],
    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    | asset_ttl - max-age (seconds) for served .js / .css assets.
    */
    'cache' => [
        'asset_ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | PWA Manifest
    |--------------------------------------------------------------------------
    | Served at the path defined in `manifest_path`. Set fields per the
    | Web App Manifest spec: https://developer.mozilla.org/docs/Web/Manifest
    */
    'manifest_path' => '/manifest.webmanifest',

    'manifest' => [
        'name' => env('APP_NAME', 'Pwax App'),
        'short_name' => env('APP_NAME', 'Pwax'),
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'background_color' => '#ffffff',
        'theme_color' => '#0c83ff',
        'icons' => [
            // [
            //     'src' => '/images/icons/icon-192.png',
            //     'sizes' => '192x192',
            //     'type' => 'image/png',
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Worker
    |--------------------------------------------------------------------------
    | enabled        - When true, the service worker is registered and served.
    | path           - URL path the service worker is served from (scope = '/').
    | blade          - Optional Blade view used to render the worker source.
    | cache_name     - Name of the runtime cache.
    | precache       - URLs to precache at install time.
    | network_first  - When true, prefers network with cache fallback.
    */
    'service_worker' => [
        'enabled' => false,
        'path' => '/service-worker.js',
        'blade' => null,
        'cache_name' => 'pwax-cache-v1',
        'precache' => ['/'],
        'network_first' => true,
    ],
];
