<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Facades\Pwax;
use Mxent\Pwax\Support\Shell;
use Mxent\Pwax\Tests\TestCase;

/**
 * Configuration that has to be in place *before* the service provider boots, because it
 * changes which routes get registered. Setting it inside a test would be too late, so it
 * is applied in `defineEnvironment` for this class.
 */
class CustomConfigurationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pwax.route_prefix', 'assets/spa');
        $app['config']->set('pwax.manifest_path', '/app.webmanifest');
        $app['config']->set('pwax.service_worker.path', '/sw.js');
        $app['config']->set('pwax.hash_route', true);
        $app['config']->set('pwax.helpers.global', true);
    }

    public function test_the_route_prefix_is_configurable(): void
    {
        $this->get('/assets/spa/c/' . $this->id('pages.home') . '.js')->assertOk();
        $this->get('/assets/spa/pwax.js')->assertOk();
    }

    public function test_component_urls_use_the_configured_prefix(): void
    {
        $this->assertStringStartsWith('/assets/spa/c/', Pwax::url('pages.home'));
    }

    public function test_the_default_prefix_no_longer_resolves(): void
    {
        $this->get('/__pwax__/c/' . $this->id('pages.home') . '.js')->assertNotFound();
    }

    public function test_the_manifest_path_is_configurable(): void
    {
        $this->get('/app.webmanifest')->assertOk();
        $this->get('/manifest.webmanifest')->assertNotFound();
    }

    public function test_the_service_worker_path_is_configurable(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $this->get('/sw.js')->assertOk();
    }

    public function test_hash_routing_reaches_the_runtime_config(): void
    {
        $this->assertTrue($this->app->make(Shell::class)->runtimeConfig()['hashRouting']);
    }

    /**
     * The 1.x helper names are still reachable for applications mid-migration.
     */
    public function test_the_legacy_helpers_are_defined_when_enabled(): void
    {
        $this->assertTrue(function_exists('vue'));
        $this->assertTrue(function_exists('router'));

        $this->assertSame('/about', router('pwax.test.about'));
        $this->assertIsArray(vue('pages.home', null, ['arr' => true]));
    }
}
