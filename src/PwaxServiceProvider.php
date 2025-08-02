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
            $ins = trim($ins);
            $ins = substr($ins, 1, -1);
            $insBits = explode(' from ', $ins);
            $var = count($insBits) == 2 ? $insBits[0] : null;
            if ($var) {
                $ins = $insBits[1];
            }
            $ins = explode('/', $ins);
            $module = null;
            if (Str::startsWith($ins[0], '~')) {
                $ins[0] = Str::replaceFirst('~', '', $ins[0]);
                if (Str::startsWith($ins[0], '/')) {
                    $ins[0] = Str::replaceFirst('/', '', $ins[0]);
                }
                $module = array_shift($ins);
            }
            $bladeBits = [];
            if ($module) {
                $bladeBits[] = $module;
            }
            $bladeBits[] = implode('.', $ins);
            $blade = implode('::', $bladeBits);
            $pascal = preg_replace('/[^a-zA-Z0-9]/', ' ', $blade);
            $pascal = Str::studly($pascal);

            $script = '';
            $script .= 'window.PwaxImport'.($var ?? '').$pascal.' = window.PwaxImport'.($var ?? '').$pascal.' || await (async function(){';
            $script .= 'var hd = new Headers();';
            $script .= 'hd.append("Accept", "application/json");';
            $script .= 'hd.append("X-Requested-With", "XMLHttpRequest");';
            $script .= 'hd.append("X-Vue-Component", "true");';
            $script .= 'var fv = await fetch("'.route('pwax.module', str_replace('.', '_x_', str_replace('::', '__x__', $blade))).'", {headers: hd});';
            $script .= 'var d = await fv.json();';
            $script .= 'var s = d.script ? await import(`data:text/javascript;base64,${btoa(d.script)}`) : {};';
            $script .= 'var v = d.template ? {template:d.template,...s.default} : s.default;';
            $script .= 'return '.($var ? 's.'.$var : 'v').';})()';

            return $script;
        });
    }

}
