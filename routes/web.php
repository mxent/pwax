<?php

use Illuminate\Support\Facades\Route;
use Mxent\Pwax\Http\Controllers\PwaxController;
use Mxent\Pwax\Http\Middleware\HandlePwaxRequests;

/*
|--------------------------------------------------------------------------
| Pwax routes
|--------------------------------------------------------------------------
|
| Component endpoints run through the application's own middleware stack —
| `web` by default. That matters: a component is a Blade view, and it may call
| `auth()`, read the session, or branch on a policy. In 1.x this group had no
| middleware at all, so every component rendered as a guest.
|
| Set `pwax.routes.register` to false to register these yourself instead.
|
*/

$prefix = trim((string) config('pwax.route_prefix', '__pwax__'), '/');

// Pwax's own middleware is named explicitly rather than relied upon through the group,
// so these routes behave correctly even if the application replaces the group's contents.
/** @var list<string> $middleware */
$middleware = array_values(array_unique(array_merge(
    (array) config('pwax.middleware', ['web']),
    [HandlePwaxRequests::class],
)));

Route::group(array_filter([
    'prefix' => $prefix,
    'as' => 'pwax.',
    'middleware' => $middleware,
    'domain' => config('pwax.routes.domain'),
]), function (): void {
    // A component has exactly one representation: an ES module carrying its template,
    // script, styles and scope together. One request gives the client everything, and
    // there is no second shape of URL to sign, cache or reason about.
    //
    // Identifiers are base64url + a hex signature, so the character class is tight.
    Route::get('/c/{id}.js', [PwaxController::class, 'js'])
        ->where('id', '[A-Za-z0-9_-]+')
        ->name('js');
});

/*
|--------------------------------------------------------------------------
| Static endpoints
|--------------------------------------------------------------------------
|
| The runtime bundle, the manifest and the service worker are the same for every
| visitor and never read the session, so they are deliberately kept out of the
| `web` group. Putting them in it — as 1.x did for the manifest — starts a session
| and sets a cookie on requests that have no use for either.
|
*/

Route::group(array_filter([
    'middleware' => (array) config('pwax.routes.static_middleware', []),
    'domain' => config('pwax.routes.domain'),
]), function () use ($prefix): void {
    Route::get($prefix . '/pwax.js', [PwaxController::class, 'runtime'])->name('pwax.runtime');

    Route::get(
        ltrim((string) config('pwax.manifest_path', '/manifest.webmanifest'), '/'),
        [PwaxController::class, 'manifest']
    )->name('pwax.manifest');

    // Registered unconditionally so that toggling `service_worker.enabled` at runtime
    // takes effect without rebuilding the route table; the controller returns 404 when
    // the worker is off.
    Route::get(
        ltrim((string) config('pwax.service_worker.path', '/service-worker.js'), '/'),
        [PwaxController::class, 'serviceWorker']
    )->name('pwax.service-worker');

    // The asset manifest the worker installs the application from — every vendor bundle,
    // the runtime, the offline shell and every component, each with a content hash.
    Route::get(
        ltrim((string) config('pwax.service_worker.asset_manifest.path', '/sw.json'), '/'),
        [PwaxController::class, 'assetManifest']
    )->name('pwax.asset-manifest');

    // The offline shell. Deliberately in this group and not in `web`: it must render
    // without a session so that what gets precached carries no CSRF token and no
    // signed-in user's data.
    Route::get(
        ltrim((string) config('pwax.service_worker.shell.path', '/__pwax__/shell'), '/'),
        [PwaxController::class, 'shell']
    )->name('pwax.shell');
});
