<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Facades\Pwax;
use Mxent\Pwax\Tests\TestCase;

/**
 * Resource hints for what the first render is about to import.
 *
 * Everything a page needs on its critical path is known to the server while it is writing
 * the document, and almost none of it is discoverable by the browser until much later. A
 * component imported with `@pwaxImport` is compiled into a
 * `window.pwax.component('/__pwax__/c/….js')` call inside the page's own script, so the
 * request for it cannot start until Vue has downloaded, parsed, compiled this page's
 * template and rendered it — a whole serial round trip after the framework is already up.
 * Configured plugins and directives are worse: the runtime awaits them *before* it mounts.
 *
 * Naming them in the head costs one line each and removes that round trip.
 */
class ModulePreloadTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/imports', fn () => pwaxRender('pages.imports'));
            $router->get('/plain', fn () => pwaxRender('pages.home'));
            $router->get('/assets', fn () => pwaxRender('pages.assets'));
        });
    }

    public function test_an_imported_component_is_preloaded(): void
    {
        $html = (string) $this->get('/imports')->getContent();

        $this->assertStringContainsString(
            '<link rel="modulepreload" href="' . Pwax::url('components.modal') . '">',
            $html
        );
    }

    public function test_the_hint_names_the_url_the_script_actually_imports(): void
    {
        $html = (string) $this->get('/imports')->getContent();

        // The failure this guards against is a hint that points somewhere the page never
        // asks for: a wasted request, and the real one still on the critical path.
        $this->assertSame(
            1,
            preg_match('#<link rel="modulepreload" href="([^"]+)">#', $html, $hint),
            'no modulepreload hint was emitted'
        );

        // Compared against the payload the document actually ships, decoded — the island
        // is JSON with `JSON_HEX_QUOT`, so its copy of the URL is not byte-identical to
        // the attribute's.
        $this->assertSame(
            1,
            preg_match('#<script type="application/json" id="pwax-initial">(.*?)</script>#s', $html, $island)
        );

        $payload = json_decode($island[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString(
            'window.pwax.component("' . $hint[1] . '"',
            $payload['component']['script']
        );
    }

    public function test_a_page_that_imports_nothing_gets_no_module_hints(): void
    {
        $this->get('/plain')->assertDontSee('rel="modulepreload"', false);
    }

    public function test_configured_plugins_and_directives_are_preloaded(): void
    {
        config()->set('pwax.plugins', ['store' => "@pwaxImport('components.modal')"]);

        $html = (string) $this->get('/plain')->getContent();

        // Awaited before mount, so this one is squarely on the critical path.
        $this->assertStringContainsString(
            '<link rel="modulepreload" href="' . Pwax::url('components.modal') . '">',
            $html
        );
    }

    public function test_a_global_plugin_path_is_not_preloaded(): void
    {
        // Not a module — a dotted lookup on `window`. There is nothing to fetch.
        config()->set('pwax.plugins', ['store' => 'MyApp.store']);

        $this->get('/plain')->assertDontSee('rel="modulepreload"', false);
    }

    public function test_a_page_is_hinted_only_once_for_a_module_it_shares_with_a_plugin(): void
    {
        config()->set('pwax.plugins', ['modal' => "@pwaxImport('components.modal')"]);

        $html = (string) $this->get('/imports')->getContent();

        $this->assertSame(
            1,
            substr_count($html, '<link rel="modulepreload" href="' . Pwax::url('components.modal') . '">')
        );
    }

    public function test_a_pages_external_assets_are_preloaded(): void
    {
        $html = (string) $this->get('/assets')->getContent();

        $this->assertStringContainsString(
            '<link rel="preload" as="script" href="https://cdn.example.test/chart.js">',
            $html
        );
        $this->assertStringContainsString(
            '<link rel="preload" as="style" href="https://cdn.example.test/chart.css">',
            $html
        );
    }

    public function test_the_offline_shell_hints_nothing_page_specific(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        // The shell is rendered with no component at all. A hint for a page it is not
        // showing would be a request every offline boot makes and never uses.
        $this->get('/__pwax__/shell')->assertDontSee('rel="modulepreload"', false);
    }
}
