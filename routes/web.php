<?php

use Illuminate\Support\Facades\Route;
use Mxent\Pwax\Http\Controllers\PwaxController;

Route::group(['prefix' => config('pwax.route_prefix', '__pwax__'), 'as' => 'pwax.'], function () {
    Route::get('/{name}.js', [PwaxController::class, 'js'])->name('js');
    Route::get('/{name}.css', [PwaxController::class, 'css'])->name('css');
    Route::get('/{name}', [PwaxController::class, 'module'])->name('module');
});
