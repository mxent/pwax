<?php

namespace Mxent\Pwax;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Mxent\Pwax\Console\Commands\InstallCommand;

class PwaxServiceProvider extends ServiceProvider
{
    /**
     * Register services. Bindings, config merging, and command registration
     * happen here so they are available during the boot phase.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/pwax.php', 'pwax');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->bootHelpers();
        $this->bootDirectives();
        $this->bootRoutes();
        $this->bootViews();
        $this->bootPublishing();
    }

    protected function bootHelpers(): void
    {
        // helpers.php is autoloaded via composer "files"; require_once here only
        // as a safety net for environments where autoload hasn't kicked in.
        require_once __DIR__ . '/../helpers.php';
    }

    protected function bootRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    protected function bootViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'pwax');
    }

    protected function bootDirectives(): void
    {
        Blade::directive('import', function ($expression) {
            return import($expression);
        });
    }

    protected function bootPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/pwax.php' => config_path('pwax.php'),
        ], 'pwax-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/pwax'),
        ], 'pwax-views');

        $this->publishes([
            __DIR__ . '/../resources/views/js/service-worker.blade.php'
                => resource_path('views/vendor/pwax/js/service-worker.blade.php'),
        ], 'pwax-service-worker');
    }
}
