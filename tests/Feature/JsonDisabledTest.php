<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Support\Shell;
use Mxent\Pwax\Tests\TestCase;

/**
 * What `pwax.json.enabled => false` actually turns off.
 *
 * The setting exists so an application that never renders a document does not serve, hint
 * at or precache 380 kB it has no use for. What it must not do is turn a `<PwaxJson>` into
 * a mystery: the component is still registered by the runtime, and the runtime knows the
 * feature is off because `runtime` is null.
 */
class JsonDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pwax.json.enabled', false);
        $app['config']->set('pwax.json.components', ['Card' => "@pwaxImport('components.badge')"]);
    }

    public function test_the_endpoint_is_a_404_rather_than_serving_a_bundle_nothing_can_reach(): void
    {
        $this->get('/__pwax__/pwax-json.js')->assertNotFound();
    }

    public function test_the_runtime_is_told_the_feature_is_off_rather_than_told_nothing(): void
    {
        $json = $this->app->make(Shell::class)->runtimeConfig()['json'];

        $this->assertSame(
            ['enabled' => false, 'runtime' => null, 'components' => [], 'actions' => []],
            $json,
            'Every field is present and empty on purpose. A missing key reads to the runtime '
            . 'as a runtime that was never told anything, which is a different bug.'
        );
    }

    public function test_the_configured_catalog_does_not_reach_the_browser(): void
    {
        $html = (string) $this->get('/__pwax__/pwax-json.js')->getContent();

        $this->assertStringNotContainsString('components.badge', $html);
    }
}
