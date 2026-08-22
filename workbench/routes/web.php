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
    // Rendered with controller data, so it would otherwise be left to the SPA — the
    // heading is the same for everyone, and this is how a route says so.
    ->prerenderable()
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
