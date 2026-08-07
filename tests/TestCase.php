<?php

namespace Mxent\Pwax\Tests;

use Mxent\Pwax\Facades\Pwax as PwaxFacade;
use Mxent\Pwax\PwaxServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PwaxServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Pwax' => PwaxFacade::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $app['config']->set('app.debug', false);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');

        $app['config']->set('pwax.home', 'pwax.test.home');

        // Fixture components live alongside the tests rather than in the package's own
        // resources, so a broken fixture can never ship.
        $app['config']->set('view.paths', array_merge(
            [__DIR__ . '/fixtures/views'],
            $app['config']->get('view.paths', [])
        ));
    }

    protected function defineRoutes($router): void
    {
        $router->get('/', fn () => 'home')->name('pwax.test.home');
        $router->get('/about', fn () => 'about')->name('pwax.test.about');
        $router->get('/users/{id}', fn (string $id) => $id)->name('pwax.test.user');
    }

    /**
     * The signed identifier for a fixture view.
     */
    protected function id(string $view): string
    {
        return PwaxFacade::id($view);
    }

    /**
     * Headers that mark a request as coming from the client runtime.
     *
     * @return array<string, string>
     */
    protected function componentHeaders(): array
    {
        return ['X-Pwax-Component' => 'true'];
    }
}
