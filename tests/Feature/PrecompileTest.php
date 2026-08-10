<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Support\Facades\File;
use Mxent\Pwax\Pwa\ComponentRegistry;
use Mxent\Pwax\Pwax;
use Mxent\Pwax\Support\RenderFunctionStore;
use Mxent\Pwax\Support\Shell;
use Mxent\Pwax\Tests\TestCase;
use Throwable;

/**
 * The opt-in precompile mode.
 *
 * `php artisan pwax:compile` turns every template into a Vue render function so the app
 * can ship the runtime-only Vue build and drop `script-src 'unsafe-eval'`. It is off by
 * default and must stay invisible when it is: the zero-build path is the package's premise,
 * and every assertion here that concerns the default configuration is guarding that.
 *
 * The half-configured states matter more than the happy one. An application that asks for
 * the runtime build without compiling gets the full build, not a blank page; one that
 * compiles and then edits a component is the case that actually breaks, and `pwax:doctor`
 * is what has to catch it.
 */
class PrecompileTest extends TestCase
{
    private string $storePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storePath = sys_get_temp_dir() . '/pwax-render-functions-' . getmypid() . '.php';

        config()->set('pwax.assets.render_functions', $this->storePath);

        $this->store()->forget();
    }

    protected function tearDown(): void
    {
        if (is_file($this->storePath)) {
            @unlink($this->storePath);
        }

        parent::tearDown();
    }

    private function store(): RenderFunctionStore
    {
        return $this->app->make(RenderFunctionStore::class);
    }

    private function template(string $view): string
    {
        return $this->app->make(Pwax::class)->compile($view)->template;
    }

    /**
     * Seed the store as though `pwax:compile` had run over the given views.
     *
     * The bodies are stand-ins: nothing here executes JavaScript, and every assertion is
     * about which source reaches the browser rather than what it evaluates to.
     *
     * @param  list<string>  $views
     */
    private function precompiled(array $views): void
    {
        $functions = [];
        $covered = [];

        foreach ($views as $view) {
            $template = $this->template($view);
            $key = RenderFunctionStore::key($template);

            $functions[$key] = "return function render(_ctx) { return null }\n// {$view}";
            $covered[$view] = $key;
        }

        $this->store()->write($functions, ['vue' => '3.5.41', 'views' => $covered]);
    }

    /**
     * Every view the doctor will try to account for, minus the ones that cannot render
     * without controller data — which `pwax:compile` reports and skips.
     *
     * @return list<string>
     */
    private function compilableViews(): array
    {
        $views = [];

        foreach ($this->app->make(ComponentRegistry::class)->precachable() as $component) {
            try {
                if (trim($this->template($component['view'])) !== '') {
                    $views[] = $component['view'];
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $views;
    }

    // -----------------------------------------------------------------------------
    // The store
    // -----------------------------------------------------------------------------

    public function test_the_store_is_empty_until_something_is_written(): void
    {
        $this->assertSame(0, $this->store()->count());
        $this->assertFalse($this->store()->isComplete());
        $this->assertNull($this->store()->get('<div></div>'));
    }

    public function test_a_written_render_function_survives_a_round_trip_through_the_file(): void
    {
        $this->store()->write(['abc' => 'return function render() {}'], ['vue' => '3.5.41']);

        // A second instance, reading the file rather than the memo it just wrote.
        $this->store()->forget();

        $this->assertSame(1, $this->store()->count());
        $this->assertSame('3.5.41', $this->store()->meta()['vue']);
    }

    public function test_the_key_is_the_template_so_an_edited_template_simply_misses(): void
    {
        $this->store()->write([RenderFunctionStore::key('<p>a</p>') => 'return function render() {}']);

        $this->assertNotNull($this->store()->get('<p>a</p>'));
        $this->assertNull($this->store()->get('<p>b</p>'));
    }

    /**
     * A deploy interrupted mid-copy leaves a file that will not parse. Falling back to
     * "nothing is precompiled" is the default path; taking the application down is not.
     */
    public function test_an_unparsable_store_is_treated_as_empty(): void
    {
        File::ensureDirectoryExists(dirname($this->storePath));
        File::put($this->storePath, "<?php\n\nreturn [");

        $this->store()->forget();

        $this->assertSame(0, $this->store()->count());
    }

    public function test_flush_removes_the_file(): void
    {
        $this->store()->write(['abc' => 'return function render() {}']);

        $this->assertFileExists($this->storePath);

        $this->store()->flush();

        $this->assertFileDoesNotExist($this->storePath);
        $this->assertFalse($this->store()->isComplete());
    }

    /**
     * The compiler emits a chunk ending in `return function render(…)`, so the wrapper is
     * an arrow function that is called immediately — not a `new Function`, which is the
     * one construct this whole mode exists to remove.
     */
    public function test_the_bindings_wrap_the_compiler_output_in_a_module_export(): void
    {
        $this->store()->write([
            RenderFunctionStore::key('<p></p>') => 'return function render(_ctx) { return null }',
        ]);

        $bindings = $this->store()->bindings('<p></p>');

        $this->assertStringContainsString('const __pwaxRender = (() => {', $bindings);
        $this->assertStringContainsString('return function render(_ctx)', $bindings);
        $this->assertStringContainsString('export { __pwaxRender };', $bindings);
        $this->assertStringNotContainsString('new Function', $bindings);
    }

    // -----------------------------------------------------------------------------
    // Which Vue build is served
    // -----------------------------------------------------------------------------

    public function test_the_full_build_is_served_by_default(): void
    {
        $this->assertStringContainsString('vue.global.prod.js', $this->vendorSources());
        $this->assertStringNotContainsString('vue.runtime.global', $this->vendorSources());
    }

    public function test_the_full_build_is_served_even_when_render_functions_exist(): void
    {
        $this->precompiled(['pages.home']);

        $this->assertStringNotContainsString('vue.runtime.global', $this->vendorSources());
    }

    public function test_the_runtime_build_is_served_once_asked_for_and_compiled(): void
    {
        config()->set('pwax.assets.vue_build', 'runtime');
        $this->precompiled(['pages.home']);

        $this->assertStringContainsString('vue.runtime.global.prod.js', $this->vendorSources());
    }

    /**
     * Asking for the runtime build without compiling is a performance regression, not an
     * outage. `pwax:doctor` is where it is reported.
     */
    public function test_asking_for_the_runtime_build_without_compiling_falls_back(): void
    {
        config()->set('pwax.assets.vue_build', 'runtime');

        $this->assertStringNotContainsString('vue.runtime.global', $this->vendorSources());
        $this->assertStringContainsString('vue.global.prod.js', $this->vendorSources());
    }

    /**
     * Vue ships two builds and Pwax serves either. An integrity map keyed only on the
     * package name would send the full build's digest with the runtime-only file, and a
     * browser refuses a script whose digest does not match — the whole app, not loading.
     */
    public function test_the_cdn_integrity_hash_follows_the_build_actually_served(): void
    {
        $this->healthyConfiguration();
        config()->set('pwax.assets.vue_build', 'runtime');
        $this->precompiled(['pages.home']);

        $vue = collect($this->app->make(Shell::class)->frameworkScripts())
            ->first(fn (array $tag): bool => str_contains((string) $tag['src'], 'vue.runtime.global'));

        $this->assertNotNull($vue, 'The runtime build was not requested from the CDN.');
        $this->assertSame(
            config('pwax.assets.cdn.integrity')['vue.runtime.global.prod.js'],
            $vue['integrity']
        );
    }

    private function vendorSources(): string
    {
        return implode(' ', array_column($this->app->make(Shell::class)->vendorScripts(), 'src'));
    }

    // -----------------------------------------------------------------------------
    // What reaches the browser
    // -----------------------------------------------------------------------------

    public function test_a_component_module_carries_no_render_function_by_default(): void
    {
        $body = $this->get('/__pwax__/c/' . $this->id('components.modal') . '.js')->getContent();

        $this->assertStringNotContainsString('__pwaxRender', $body);
        $this->assertStringContainsString('const __pwaxTemplate =', $body);
    }

    public function test_a_component_module_exports_its_precompiled_render_function(): void
    {
        $this->precompiled(['components.modal']);

        $body = $this->get('/__pwax__/c/' . $this->id('components.modal') . '.js')->getContent();

        $this->assertStringContainsString('const __pwaxRender = (() => {', $body);
        $this->assertStringContainsString('export { __pwaxRender };', $body);

        // The template goes too. An author who wrote their own `template` still wins, and
        // the client needs the Blade one to fall back to under the full build.
        $this->assertStringContainsString('const __pwaxTemplate =', $body);
    }

    /**
     * A page is not addressable by view name — it may have been rendered with controller
     * data — so its script travels inline. The render function has to travel with it, in
     * the script rather than in a field of its own: the client evaluates the script as a
     * module, and a render function handed over as a separate string would need
     * `new Function` to become callable.
     */
    public function test_a_page_payload_carries_its_render_function_inside_the_inline_script(): void
    {
        $this->precompiled(['pages.home']);

        $payload = $this->app->make(Pwax::class)->render('pages.home')->toArray();

        $this->assertStringContainsString('const __pwaxRender = (() => {', $payload['script']);
        $this->assertStringContainsString('export { __pwaxRender };', $payload['script']);

        // And the author's own script is still there, after it.
        $this->assertStringContainsString('export default', $payload['script']);
    }

    public function test_a_page_payload_is_untouched_when_nothing_is_compiled(): void
    {
        $payload = $this->app->make(Pwax::class)->render('pages.home')->toArray();

        $this->assertStringNotContainsString('__pwaxRender', $payload['script']);
    }

    /**
     * An addressable component's render function is in the module the client imports, so
     * repeating it in the payload would ship the same source twice.
     */
    public function test_an_addressable_payload_does_not_repeat_the_render_function(): void
    {
        $this->precompiled(['components.modal']);

        $pwax = $this->app->make(Pwax::class);
        $payload = $pwax->payload($pwax->compile('components.modal'));

        $this->assertNotNull($payload['module']);
        $this->assertStringNotContainsString('__pwaxRender', $payload['script']);
    }

    // -----------------------------------------------------------------------------
    // pwax:doctor
    // -----------------------------------------------------------------------------

    /**
     * Everything else the doctor checks, satisfied — so these tests fail on the one thing
     * they are about rather than on a fixture app with no icons.
     */
    private function healthyConfiguration(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);

        // Not published in the test app, and publishing is not what is under test.
        config()->set('pwax.assets.source', 'cdn');
    }

    public function test_the_doctor_says_nothing_about_precompiling_by_default(): void
    {
        $this->healthyConfiguration();

        $this->artisan('pwax:doctor')
            ->doesntExpectOutputToContain('pwax:compile')
            ->assertSuccessful();
    }

    public function test_the_doctor_reports_the_runtime_build_with_nothing_compiled(): void
    {
        $this->healthyConfiguration();
        config()->set('pwax.assets.vue_build', 'runtime');

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('no render functions are compiled')
            ->assertFailed();
    }

    public function test_the_doctor_reports_a_component_changed_since_the_last_compile(): void
    {
        $this->healthyConfiguration();
        config()->set('pwax.assets.vue_build', 'runtime');

        // Everything but one, which stands in for a component edited after compiling:
        // its template hashes to a key the store does not have.
        $views = $this->compilableViews();
        array_pop($views);

        $this->precompiled($views);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('have changed since')
            ->assertFailed();
    }

    public function test_the_doctor_is_happy_when_every_component_is_covered(): void
    {
        $this->healthyConfiguration();
        config()->set('pwax.assets.vue_build', 'runtime');

        $this->precompiled($this->compilableViews());

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('Render functions cover all')
            ->assertSuccessful();
    }

    public function test_the_doctor_warns_when_render_functions_are_compiled_but_unused(): void
    {
        $this->healthyConfiguration();

        $this->precompiled(['pages.home']);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('compiled but unused')
            ->assertSuccessful();
    }

    // -----------------------------------------------------------------------------
    // pwax:compile
    // -----------------------------------------------------------------------------

    public function test_the_compile_command_clears_the_store(): void
    {
        $this->precompiled(['pages.home']);

        $this->artisan('pwax:compile', ['--clear' => true])->assertSuccessful();

        $this->assertFileDoesNotExist($this->storePath);
    }

    /**
     * The whole way through: discover the components, render them, hand the batch to Node,
     * and write something a browser could actually run.
     */
    public function test_the_compile_command_produces_real_render_functions(): void
    {
        $this->requireCompiler();

        // One view, so the assertion is about the output rather than about which of the
        // fixtures happen to render without data.
        config()->set('pwax.service_worker.components', ['components.modal']);

        $this->artisan('pwax:compile')->assertSuccessful();

        $this->store()->forget();

        $render = $this->store()->get($this->template('components.modal'));

        $this->assertNotNull($render, 'components.modal was not compiled.');
        $this->assertStringContainsString('return function render', $render);

        // `prefixIdentifiers` is not optional: `with (_ctx)` is a SyntaxError in a module,
        // and every component Pwax serves is a module.
        $this->assertStringNotContainsString('with (_ctx)', $render);

        // The helpers come off the global `Vue`, which is how the runtime-only build
        // exposes them to a component module.
        $this->assertStringContainsString('} = Vue', $render);

        $this->assertSame(
            ['components.modal' => RenderFunctionStore::key($this->template('components.modal'))],
            $this->store()->views()
        );
    }

    /**
     * A view that cannot render without controller data cannot be precompiled. Reporting
     * it by name and failing is the point: silently compiling everything else would leave
     * a store that looks complete and an application that renders one page blank.
     */
    public function test_the_compile_command_names_a_view_it_cannot_render(): void
    {
        $this->requireCompiler();

        config()->set('pwax.service_worker.components', ['components.modal', 'pages.needs-model']);

        $this->artisan('pwax:compile')
            ->expectsOutputToContain('pages.needs-model')
            ->assertFailed();
    }

    public function test_the_compile_command_reports_a_mismatched_compiler_version(): void
    {
        $this->requireCompiler();

        config()->set('pwax.assets.versions.vue', '2.0.0');
        config()->set('pwax.service_worker.components', ['components.modal']);

        $this->artisan('pwax:compile')
            ->expectsOutputToContain('does not match')
            ->assertFailed();
    }

    /**
     * `@vue/compiler-dom` is an optional peer dependency of a mode almost nobody turns on,
     * and the PHP matrix does not run `npm ci`. Skipping is honest; pretending is not.
     */
    private function requireCompiler(): void
    {
        $root = dirname(__DIR__, 2);

        if (! is_dir($root . '/node_modules/@vue/compiler-dom')) {
            $this->markTestSkipped('@vue/compiler-dom is not installed; run `npm ci`.');
        }

        // `base_path()` is where the command starts Node, and Node resolves
        // `@vue/compiler-dom` from there upwards — so it has to be the package root.
        $this->app->setBasePath($root);
    }
}
