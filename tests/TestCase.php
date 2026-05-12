<?php

namespace Mxent\Pwax\Tests;

use Mxent\Pwax\PwaxServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PwaxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('pwax.home', 'pwax.test.home');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/', function () {
            return 'home';
        })->name('pwax.test.home');

        $router->get('/about', function () {
            return 'about';
        })->name('pwax.test.about');
    }
}
