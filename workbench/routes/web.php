<?php

/*
|--------------------------------------------------------------------------
| The demo application's routes
|--------------------------------------------------------------------------
|
| Between them these cover the four things CONTRIBUTING.md asks you to check by hand
| after touching the runtime or the shell: a cold load that needs no component fetch
| before first paint, an A -> B -> A walk that must leave one stylesheet per live
| component, a redirect that has to travel through the SPA router, and an app that still
| boots with DevTools set to offline.
|
*/

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => pwaxRender('pages.home', ['heading' => 'Pwax'])
    // Rendered with controller data, so its payload is `no-store` unless the route says
    // otherwise. The heading is the same for everyone, and this is how a route says so —
    // which is also what puts the page in the offline cache.
    ->cacheable()
    ->title('Home')
    ->description('A demo application for working on the package.')
    ->canonical(url('/'))
    ->jsonLd([
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Pwax Demo',
        'url' => url('/'),
    ]))->name('index');

Route::get('/about', fn () => pwaxRender('pages.about')
    ->title('About')
    ->description('The second page, so navigation has somewhere to go.')
    ->canonical(url('/about'))
    ->alternate('en', url('/about'))
    ->alternate('fr', url('/fr/about'))
    ->jsonLd([
        '@context' => 'https://schema.org',
        '@type' => 'AboutPage',
        'name' => 'About',
    ]))->name('about');

// A redirect from a Pwax-served route. The runtime asks for a component payload and must
// be told to navigate rather than be handed the redirect target's HTML — which is what
// `HandlePwaxRequests` translates into `X-Pwax-Location`.
Route::get('/elsewhere', fn () => redirect()->route('about'))->name('elsewhere');

/*
|--------------------------------------------------------------------------
| Data that arrives after mount
|--------------------------------------------------------------------------
|
| A page whose list comes from a `fetch` in `mounted()`. Visit it once, set DevTools to
| offline and reload: the document, the runtime, the component and the JSON are all in
| the worker's caches, so it renders and fills itself in with no network at all.
|
*/

Route::get('/items', fn () => pwaxRender('pages.items')
    ->title('Items')
    ->description('A list fetched over HTTP, and still there offline.'))
    ->name('items');

Route::get('/api/items', fn () => response()->json([
    'items' => [
        ['id' => 1, 'title' => 'First'],
        ['id' => 2, 'title' => 'Second'],
        ['id' => 3, 'title' => 'Third'],
    ],
])->header('Cache-Control', 'public, max-age=60'))->name('api.items');
