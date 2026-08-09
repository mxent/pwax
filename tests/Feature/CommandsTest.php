<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Support\Facades\File;
use Mxent\Pwax\Facades\Pwax;
use Mxent\Pwax\Tests\TestCase;

class CommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('views/scratch'));

        parent::tearDown();
    }

    public function test_the_clear_command_runs(): void
    {
        $this->artisan('pwax:clear')->assertSuccessful();
    }

    public function test_the_component_command_scaffolds_a_view(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.widget'])->assertSuccessful();

        $path = resource_path('views/scratch/widget.blade.php');
        $this->assertFileExists($path);

        $contents = File::get($path);
        $this->assertStringContainsString('<template>', $contents);
        $this->assertStringContainsString('<script>', $contents);
        $this->assertStringContainsString('<style scoped>', $contents);

        // Blade's own interpolation must be escaped so Vue receives it intact.
        $this->assertStringContainsString('@{{ title }}', $contents);
    }

    public function test_the_scaffolded_component_compiles(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.widget'])->assertSuccessful();

        $component = Pwax::compile('scratch.widget');

        $this->assertStringContainsString('{{ title }}', $component->template);
        $this->assertStringContainsString('export default', $component->script);
        $this->assertNotNull($component->scopeId);
    }

    public function test_the_component_command_can_omit_styles(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.plain', '--plain' => true])->assertSuccessful();

        $this->assertStringNotContainsString('<style', File::get(resource_path('views/scratch/plain.blade.php')));
    }

    public function test_the_component_command_refuses_to_overwrite(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.widget'])->assertSuccessful();
        $this->artisan('pwax:component', ['name' => 'scratch.widget'])->assertFailed();
        $this->artisan('pwax:component', ['name' => 'scratch.widget', '--force' => true])->assertSuccessful();
    }

    public function test_the_component_command_rejects_unsafe_names(): void
    {
        $this->artisan('pwax:component', ['name' => '../../../etc/passwd'])->assertFailed();
    }

    public function test_the_doctor_command_reports_problems(): void
    {
        // No icons configured, so the app is not installable.
        $this->artisan('pwax:doctor')->assertFailed();
    }

    public function test_the_doctor_command_passes_a_complete_configuration(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.strategy', 'cdn');

        $this->artisan('pwax:doctor')->assertSuccessful();
    }

    public function test_the_doctor_command_flags_the_import_directive(): void
    {
        config()->set('pwax.components.directive', 'import');

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('@import')
            ->assertFailed();
    }

    public function test_the_doctor_command_flags_missing_middleware(): void
    {
        config()->set('pwax.middleware', []);

        $this->artisan('pwax:doctor')->assertFailed();
    }

    public function test_the_doctor_command_flags_an_app_that_cannot_start_offline(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.assets', false);
        config()->set('pwax.service_worker.shell.enabled', false);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('offline')
            ->assertFailed();
    }

    /**
     * Cross-origin isolation is on by default, and a cross-origin asset loaded without
     * `crossorigin` is the kind of misconfiguration the browser turns into a silent
     * missing asset. The doctor is the place that catches it before deploy.
     */
    public function test_the_doctor_command_flags_cross_origin_assets_without_crossorigin(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.strategy', 'cdn');
        config()->set('pwax.scripts', ['https://cdn.example.test/analytics.js']);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('crossorigin')
            ->assertSuccessful();
    }

    /**
     * Same-origin assets load without `crossorigin`, so they are not flagged.
     */
    public function test_the_doctor_command_does_not_flag_same_origin_assets(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.strategy', 'cdn');
        config()->set('pwax.scripts', ['/js/local.js']);

        $this->artisan('pwax:doctor')->assertSuccessful();
    }

    public function test_the_precache_command_lists_what_is_cached(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $this->artisan('pwax:precache')
            ->expectsOutputToContain('/__pwax__/pwax.js')
            ->expectsOutputToContain('available offline')
            ->assertSuccessful();
    }

    public function test_the_precache_command_can_emit_the_manifest(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $this->artisan('pwax:precache', ['--json' => true])
            ->expectsOutputToContain('"hashTable"')
            ->assertSuccessful();
    }

    public function test_the_precache_command_reports_excluded_components(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.components', ['components.*']);

        $this->artisan('pwax:precache')
            ->expectsOutputToContain('excluded')
            ->assertSuccessful();
    }

    public function test_the_precache_command_verifies_components_render(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        // `pages.needs-model` cannot be rendered from its view name alone, so it cannot
        // be precached — which is exactly what --verify exists to surface.
        config()->set('pwax.service_worker.components', ['pages.needs-model']);

        $this->artisan('pwax:precache', ['--verify' => true])->assertFailed();
    }

    public function test_the_precache_command_passes_when_every_component_renders(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.components', ['components.*']);

        $this->artisan('pwax:precache', ['--verify' => true])->assertSuccessful();
    }

    public function test_the_install_command_publishes_the_config(): void
    {
        $this->artisan('pwax:install', ['--no-assets' => true, '--force' => true])->assertSuccessful();

        $this->assertFileExists(config_path('pwax.php'));

        File::delete(config_path('pwax.php'));
    }
}
