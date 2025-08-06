<?php

use Illuminate\Support\Facades\Route;
use Mxent\Pwax\Http\Controllers\PwaxController;

Route::group(['prefix' => config('pwax.route_prefix', '__pwax__'), 'as' => 'pwax.'], function () {
    Route::get('/dist/{name}.js', [PwaxController::class, 'distJs'])->name('dist.js');
    Route::get('/dist/{name}.css', [PwaxController::class, 'distCss'])->name('dist.css');
    Route::get('/{name}.js', [PwaxController::class, 'js'])->name('js');
    Route::get('/{name}.css', [PwaxController::class, 'css'])->name('css');
    Route::get('/{name}.json', [PwaxController::class, 'module'])->name('module');
});
