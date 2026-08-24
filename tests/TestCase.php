<?php

namespace Mxent\Pwax\Tests;

use Illuminate\Filesystem\Filesystem;
use Mxent\Pwax\Facades\Pwax as PwaxFacade;
use Mxent\Pwax\PwaxServiceProvider;
use Mxent\Pwax\Support\Shell;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Everything a `vendor:publish` in a test can leave behind, relative to the skeleton.
     *
     * The testbench "application" lives inside `vendor/`, and it outlives the process. A
     * published `config/pwax.php` therefore *shadows the package's own defaults* for every
     * later run — so a change to a default shows up as a failure in an unrelated test, and
     * the suite goes on testing whatever was current the last time a publishing test ran.
     * That cost an afternoon once; it is not going to cost another.
     *
     * @var list<string>
     */
    private const PUBLISHED = [
        'config/pwax.php',
        'resources/views/vendor/pwax',
        '.ai/skills/pwax',
    ];

    /**
     * The skeleton's path, read before a test can move it.
     *
     * `PrecompileTest` calls `setBasePath()` to point the application at the package root,
     * because that is where Node has to resolve `@vue/compiler-dom` from. Reading the base
     * path in `tearDown()` instead would therefore hand the cleanup below this repository
     * — and `config/pwax.php` is on its list.
     */
    private ?string $skeletonPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skeletonPath = $this->app?->basePath();
    }

    protected function tearDown(): void
    {
        $skeleton = $this->skeletonPath;

        parent::tearDown();

        if (is_string($skeleton)) {
            $this->forgetPublishedFiles($skeleton);
        }
    }

    /**
     * Remove whatever this test published into the skeleton.
     *
     * Runs for every test rather than only the ones that publish: a test that fails
     * half-way through has still written the file, and cleanup that only happens on the
     * happy path is cleanup that is missing exactly when it is needed.
     */
    private function forgetPublishedFiles(string $base): void
    {
        // Nothing here is worth deleting a file in the repository for. Every path on the
        // list also exists in the package itself, so a base path that has drifted to the
        // package root turns this cleanup into `rm config/pwax.php`.
        if (realpath($base) === realpath(dirname(__DIR__))) {
            return;
        }

        $files = new Filesystem;

        foreach (self::PUBLISHED as $path) {
            $absolute = $base . '/' . $path;

            if ($files->isDirectory($absolute)) {
                $files->deleteDirectory($absolute);

                continue;
            }

            $files->delete($absolute);
        }
    }

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

    /**
     * The runtime bundle's URL, fingerprint and all.
     *
     * Asked of the same object the shell and the manifest ask, so a test cannot pass
     * against a URL nothing actually serves.
     */
    protected function runtimeUrl(): string
    {
        return $this->app->make(Shell::class)->runtimeUrl();
    }
}
