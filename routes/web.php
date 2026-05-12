<?php

use Illuminate\Support\Facades\Route;
use Mxent\Pwax\Http\Controllers\PwaxController;

Route::group([
    'prefix' => config('pwax.route_prefix', '__pwax__'),
    'as' => 'pwax.',
], function () {
    Route::get('/{name}.js', [PwaxController::class, 'js'])
        ->where('name', '[A-Za-z0-9_-]+')
        ->name('js');

    Route::get('/{name}.css', [PwaxController::class, 'css'])
        ->where('name', '[A-Za-z0-9_-]+')
        ->name('css');

    Route::get('/{name}.json', [PwaxController::class, 'module'])
        ->where('name', '[A-Za-z0-9_-]+')
        ->name('module');
});

// Public PWA endpoints (not behind the internal prefix).
Route::middleware('web')->group(function () {
    Route::get(
        ltrim(config('pwax.manifest_path', '/manifest.webmanifest'), '/'),
        [PwaxController::class, 'manifest']
    )->name('pwax.manifest');

    Route::get(
        ltrim(config('pwax.service_worker.path', '/service-worker.js'), '/'),
        [PwaxController::class, 'serviceWorker']
    )->name('pwax.service-worker');
});
