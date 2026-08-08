<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Support\Shell;
use Mxent\Pwax\Tests\TestCase;

class ShellTest extends TestCase
{
    private function shell(): Shell
    {
        return $this->app->make(Shell::class);
    }

    public function test_serves_vue_locally_by_default(): void
    {
        $sources = array_column($this->shell()->vendorScripts(), 'src');

        $this->assertStringStartsWith('/vendor/pwax/vue.global.prod.js', $sources[0]);
        $this->assertStringContainsString('vue-router.global.prod.js', $sources[1]);
        $this->assertStringContainsString('pinia.iife.prod.js', $sources[2]);
    }

    /**
     * Each asset is busted on its own version. Tagging them all with Vue's would
     * invalidate Pinia and Vue Router on every Vue patch release.
     */
    public function test_each_local_asset_carries_its_own_version(): void
    {
        $sources = array_column($this->shell()->vendorScripts(), 'src');

        $this->assertStringContainsString('?v=' . config('pwax.assets.versions.vue'), $sources[0]);
        $this->assertStringContainsString('?v=' . config('pwax.assets.versions.vue-router'), $sources[1]);
        $this->assertStringContainsString('?v=' . config('pwax.assets.versions.pinia'), $sources[2]);
    }

    /**
     * Vue Router and Pinia are IIFE bundles that read the global `Vue` when they
     * evaluate, so Vue has to come first.
     */
    public function test_vue_is_ordered_before_its_dependents(): void
    {
        $sources = array_column($this->shell()->vendorScripts(), 'src');
        $vue = array_search(true, array_map(fn ($s) => str_contains($s, '/vue.global'), $sources), true);
        $router = array_search(true, array_map(fn ($s) => str_contains($s, 'vue-router'), $sources), true);

        $this->assertLessThan($router, $vue);
    }

    /**
     * The runtime-only build has no template compiler, and compiling templates in the
     * browser is the entire premise of the package.
     */
    public function test_uses_the_full_vue_build_not_the_runtime_only_one(): void
    {
        $sources = implode(' ', array_column($this->shell()->vendorScripts(), 'src'));

        $this->assertStringNotContainsString('vue.runtime.global', $sources);
    }

    public function test_the_runtime_bundle_is_loaded_last(): void
    {
        $sources = array_column($this->shell()->vendorScripts(), 'src');

        $this->assertStringContainsString('pwax.js', end($sources));
    }

    public function test_pinia_can_be_turned_off(): void
    {
        config()->set('pwax.assets.pinia', false);

        $sources = implode(' ', array_column($this->shell()->vendorScripts(), 'src'));

        $this->assertStringNotContainsString('pinia', $sources);
    }

    public function test_cdn_mode_emits_integrity_and_crossorigin(): void
    {
        config()->set('pwax.assets.strategy', 'cdn');

        $vue = $this->shell()->vendorScripts()[0];

        $this->assertStringContainsString('cdn.jsdelivr.net', $vue['src']);
        $this->assertStringContainsString('vue@3.5.41', $vue['src']);
        $this->assertStringStartsWith('sha384-', $vue['integrity']);
        $this->assertSame('anonymous', $vue['crossorigin']);
    }

    public function test_extra_scripts_accept_a_string_or_an_attribute_array(): void
    {
        config()->set('pwax.scripts', [
            'https://example.test/a.js',
            ['src' => 'https://example.test/b.js', 'integrity' => 'sha384-x', 'defer' => true],
        ]);

        $sources = array_column($this->shell()->vendorScripts(), 'src');

        $this->assertContains('https://example.test/a.js', $sources);
        $this->assertContains('https://example.test/b.js', $sources);
    }

    /**
     * The service worker needs the framework separated from the application's own extra
     * scripts: the framework failing to install means the app will not start, an
     * analytics tag failing means nothing at all.
     */
    public function test_framework_scripts_exclude_configured_extras_and_the_runtime(): void
    {
        config()->set('pwax.scripts', ['https://example.test/analytics.js']);

        $sources = array_column($this->shell()->frameworkScripts(), 'src');

        $this->assertCount(3, $sources);
        $this->assertNotContains('https://example.test/analytics.js', $sources);
        $this->assertStringNotContainsString('pwax.js', implode(' ', $sources));
    }

    public function test_vendor_scripts_are_preloaded(): void
    {
        $preloads = $this->shell()->vendorPreloads();

        // `<link rel="preload">` takes href, not src.
        $this->assertSame(
            array_column($this->shell()->vendorScripts(), 'src'),
            array_column($preloads, 'href')
        );
        $this->assertArrayNotHasKey('src', $preloads[0]);
    }

    /**
     * A preload whose credentials mode differs from the eventual script fetch is not
     * reused, and the browser downloads the file twice.
     */
    public function test_preloads_carry_integrity_and_crossorigin(): void
    {
        config()->set('pwax.assets.strategy', 'cdn');

        $vue = $this->shell()->vendorPreloads()[0];

        $this->assertStringStartsWith('sha384-', $vue['integrity']);
        $this->assertSame('anonymous', $vue['crossorigin']);
    }

    /**
     * The offline shell is rendered outside the `web` group precisely so that what gets
     * precached has no session-bound token in it.
     */
    public function test_the_csrf_token_is_absent_without_a_session(): void
    {
        $this->assertNull($this->shell()->csrfToken());
        $this->assertNull($this->shell()->runtimeConfig()['csrf']);
    }

    public function test_attributes_are_escaped(): void
    {
        $html = (string) $this->shell()->attributes(['src' => '/a.js?a=1&b="x"', 'defer' => true]);

        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringContainsString('&quot;', $html);
        $this->assertStringContainsString(' defer', $html);
    }

    public function test_false_and_null_attributes_are_dropped(): void
    {
        $html = (string) $this->shell()->attributes(['src' => '/a.js', 'defer' => false, 'async' => null]);

        $this->assertSame('src="/a.js"', $html);
    }

    /**
     * Configured extensions describe what to load; they are never evaluated as code.
     */
    public function test_a_module_reference_resolves_to_a_component_url(): void
    {
        config()->set('pwax.plugins', ['toast' => "@pwaxImport('components.modal')"]);

        $entry = $this->shell()->runtimeConfig()['plugins']['toast'];

        $this->assertSame('module', $entry['type']);
        $this->assertStringContainsString($this->id('components.modal'), $entry['url']);
        // Imported by the runtime with a dynamic import(), so it must be the module URL.
        $this->assertStringEndsWith('.js', $entry['url']);
    }

    public function test_a_module_reference_supports_a_named_export(): void
    {
        config()->set('pwax.plugins', ['backdrop' => "@pwaxImport('Backdrop from components.modal')"]);

        $this->assertSame('Backdrop', $this->shell()->runtimeConfig()['plugins']['backdrop']['export']);
    }

    /**
     * The 1.x `@import(...)` spelling is no longer recognised in config values.
     *
     * It was deprecated in 2.0 alongside the directive rename and is removed in 3.0. The
     * failure is quiet by construction — an unrecognised value becomes a global lookup
     * rather than an error — so this asserts the new behaviour deliberately, and
     * `pwax:doctor` is where a stale value gets reported.
     */
    public function test_the_one_x_import_spelling_is_no_longer_understood(): void
    {
        config()->set('pwax.directives', ['focus' => "@import('components.modal')"]);

        $this->assertSame('global', $this->shell()->runtimeConfig()['directives']['focus']['type']);
    }

    /**
     * A renamed directive still resolves in config values, so an application that kept
     * `@pwax` does not have to rewrite its plugin and directive lists as well as its views.
     */
    public function test_a_renamed_directive_is_understood_in_config_values(): void
    {
        config()->set('pwax.components.directive', 'pwax');
        config()->set('pwax.directives', ['focus' => "@pwax('components.modal')"]);

        $this->assertSame('module', $this->shell()->runtimeConfig()['directives']['focus']['type']);
    }

    public function test_anything_else_is_treated_as_a_global_lookup_not_as_code(): void
    {
        config()->set('pwax.plugins', ['lib' => 'MyLibrary.plugin']);

        $entry = $this->shell()->runtimeConfig()['plugins']['lib'];

        $this->assertSame(['type' => 'global', 'path' => 'MyLibrary.plugin'], $entry);
    }

    public function test_blank_extension_entries_are_ignored(): void
    {
        config()->set('pwax.plugins', ['a' => '', 'b' => null, 'c' => '   ']);

        $this->assertSame([], $this->shell()->runtimeConfig()['plugins']);
    }

    public function test_runtime_config_carries_what_the_client_needs(): void
    {
        $config = $this->shell()->runtimeConfig();

        $this->assertSame('__pwax__', $config['prefix']);
        $this->assertFalse($config['hashRouting']);
        $this->assertSame('pwax', $config['mount']);
        $this->assertNull($config['serviceWorker']);
        $this->assertArrayHasKey('content', $config['templates']);
        $this->assertArrayHasKey('loader', $config['templates']);
        $this->assertArrayHasKey('error', $config['templates']);
    }

    public function test_hash_routing_defaults_to_off_consistently(): void
    {
        // 1.x defaulted this to false in config and true in the router template.
        $this->assertFalse(config('pwax.hash_route'));
        $this->assertFalse($this->shell()->runtimeConfig()['hashRouting']);
    }

    public function test_the_service_worker_path_is_advertised_when_enabled(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $this->assertSame('/sw.js', $this->shell()->runtimeConfig()['serviceWorker']);
    }

    public function test_the_error_template_escapes_rather_than_renders_response_text(): void
    {
        // `v-html` on a value derived from the response would make reflected content
        // executable markup.
        $error = $this->shell()->templates()['error'];

        $this->assertStringContainsString('v-text', $error);
        $this->assertStringNotContainsString('v-html', $error);
    }

    public function test_templates_can_be_overridden(): void
    {
        config()->set('pwax.blade.loader', 'pwax::components.content');

        $this->assertStringContainsString('router-view', $this->shell()->templates()['loader']);
    }

    public function test_home_url_never_throws_for_an_unknown_route(): void
    {
        config()->set('pwax.home', 'route.that.does.not.exist');
        config()->set('app.debug', true);

        $this->assertSame('/', $this->shell()->runtimeConfig()['home']);
    }
}
