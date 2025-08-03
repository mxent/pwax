<?php

namespace Mxent\Pwax;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class PwaxServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->bootHelpers();
        $this->bootDirectives();
        $this->bootConfig();
        $this->bootRoutes();
        $this->bootViews();
    }

    public function register()
    {
        //
    }

    public function bootHelpers()
    {
        require_once __DIR__.'/../helpers.php';
    }

    public function bootConfig()
    {
        $this->publishes([
            __DIR__.'/../config/pwax.php' => config_path('pwax.php'),
        ], 'pwax-config');
        $this->mergeConfigFrom(
            __DIR__.'/../config/pwax.php',
            'pwax'
        );
    }

    public function bootRoutes()
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }

    public function bootViews()
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'pwax');
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/pwax'),
        ], 'pwax-views');
    }

    public function bootDirectives()
    {
        Blade::directive('import', function ($ins) {
            return import($ins);
        });
    }

}
