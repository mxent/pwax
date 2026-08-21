<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Support\Facades\View;
use Mxent\Pwax\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * Server-side rendering: the first-paint response of an eligible route is prerendered to
 * real HTML through the Node SSR bridge, so crawlers and no-JS visitors see the page's
 * content. The client runtime then hydrates the existing DOM rather than replacing it.
 *
 * These tests run the real `bin/ssr.mjs` against the vendored Vue runtime — nothing is
 * stubbed — because the one thing that matters is that the HTML the server emits hydrates
 * cleanly in the browser, and that depends on three compile options agreeing.
 */
class SsrTest extends TestCase
{
    /**
     * Whether the Node SSR bridge can actually run in this environment.
     *
     * The PHP CI matrix does not install Node or the optional `@vue/server-renderer`
     * peer dependency, so tests that assert prerendered HTML would fail there even
     * though the feature works. Tests that require a working bridge are skipped when it
     * is not available; tests that only assert the exclusion/fallback logic run
     * everywhere, because those paths do not invoke Node at all.
     */
    private static bool $nodeAvailable = false;

    /** Why the bridge could not render, for the skip message. */
    private static string $nodeUnavailableReason = 'Node is not installed.';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Probe once: can `bin/ssr.mjs` render a trivial component? This is the same
        // check `pwax:doctor` performs, so the two agree about what "available" means.
        $script = dirname(__DIR__, 2) . '/bin/ssr.mjs';

        if (! is_file($script)) {
            self::$nodeUnavailableReason = "The bridge script is missing at {$script}.";

            return;
        }

        $process = new Process(
            ['node', $script],
            dirname($script, 2),
            null,
            (string) json_encode([
                'version' => '3.5.41',
                'component' => ['template' => '<div></div>'],
                'data' => [],
            ], JSON_THROW_ON_ERROR),
            10,
        );

        try {
            $process->run();

            /** @var array<string, mixed>|null $result */
            $result = json_decode($process->getOutput(), true, 512);

            self::$nodeAvailable = ($result['ok'] ?? false) === true;

            if (! self::$nodeAvailable) {
                // The bridge reports a missing peer dependency — `vue` above all — as JSON
                // on stdout. Naming it is the difference between "SSR tests skipped" and
                // knowing that one `npm install` would run them.
                self::$nodeUnavailableReason = (string) ($result['message'] ?? trim($process->getErrorOutput()) ?: 'Unknown error.');
            }
        } catch (\Throwable $e) {
            self::$nodeAvailable = false;
            self::$nodeUnavailableReason = $e->getMessage();
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // SSR is opt-in; turn it on for these tests.
        $app['config']->set('pwax.ssr.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            // A data-free page: always eligible for SSR.
            $router->get('/ssr-home', fn () => pwaxRender('pages.home'))->name('ssr.home');

            // A page rendered with controller data but declared cacheable: eligible.
            $router->get('/ssr-named', fn () => pwaxRender('pages.with-data', ['name' => 'Ada'])->cacheable(600))
                ->name('ssr.named');

            // A page rendered with controller data but NOT cacheable: not eligible.
            $router->get('/ssr-private', fn () => pwaxRender('pages.with-data', ['name' => 'Hidden']))
                ->name('ssr.private');

            // A page rendered with controller data that declares itself prerenderable
            // without asking for its payload to be cached: eligible on that claim alone.
            $router->get('/ssr-forced', fn () => pwaxRender('pages.with-data', ['name' => 'Grace'])->prerenderable())
                ->name('ssr.forced');

            // A page whose CSS carries a sequence that would terminate a <style> block.
            $router->get('/ssr-style-escape', fn () => pwaxRender('pages.style-escape'))
                ->name('ssr.style-escape');

            // A page that opts out of SSR explicitly.
            $router->get('/ssr-spa-only', fn () => pwaxRender('pages.home')->spaOnly())
                ->name('ssr.spa-only');

            // A page that is excluded by config pattern.
            $router->get('/ssr-excluded', fn () => pwaxRender('pages.scoped'))
                ->name('ssr.excluded');
        });
    }

    /**
     * Skip a test that requires a working Node SSR bridge.
     *
     * The PHP CI matrix runs without Node; these tests are only meaningful where the
     * bridge can actually render. The exclusion and fallback tests do not call Node and
     * run everywhere.
     */
    private function requireNode(): void
    {
        if (! self::$nodeAvailable) {
            $this->markTestSkipped('The Node SSR bridge is not available here: ' . self::$nodeUnavailableReason);
        }
    }

    public function test_a_data_free_page_is_prerendered(): void
    {
        $this->requireNode();

        $response = $this->get('/ssr-home');

        $response->assertOk();
        $response->assertHeader('X-Pwax-SSR', '1');

        // The prerendered content is in the mount element, not just a JSON island.
        $this->assertStringContainsString('<h1>Home</h1>', (string) $response->getContent());
        $this->assertStringContainsString('data-pwax-prerendered', (string) $response->getContent());
    }

    public function test_a_prerendered_page_carries_the_state_island(): void
    {
        $this->requireNode();

        $response = $this->get('/ssr-home');

        $html = (string) $response->getContent();

        $this->assertStringContainsString('id="pwax-state"', $html);
        $this->assertStringContainsString('data-pwax-state', $html);

        // The state island decodes to the component's resolved data.
        preg_match('/id="pwax-state"[^>]*>(.*?)<\/script>/s', $html, $matches);
        $this->assertNotEmpty($matches, 'pwax-state island is present');

        $state = json_decode((string) $matches[1], true);

        $this->assertSame('Home', $state['title'] ?? null);
    }

    public function test_a_prerendered_page_has_the_hydrate_flag_in_the_initial_payload(): void
    {
        $this->requireNode();

        $response = $this->get('/ssr-home');

        $html = (string) $response->getContent();

        preg_match('/id="pwax-initial"[^>]*>(.*?)<\/script>/s', $html, $matches);
        $this->assertNotEmpty($matches, 'pwax-initial island is present');

        $payload = json_decode((string) $matches[1], true);

        $this->assertTrue($payload['hydrate'] ?? false);
    }

    public function test_a_cacheable_page_with_data_is_prerendered(): void
    {
        $this->requireNode();

        $response = $this->get('/ssr-named');

        $response->assertOk();
        $response->assertHeader('X-Pwax-SSR', '1');

        // The with-data fixture renders `Hello {{ $name }}` with the controller data.
        $this->assertStringContainsString('Hello Ada', (string) $response->getContent());
    }

    public function test_a_non_cacheable_page_with_data_is_not_prerendered(): void
    {
        $response = $this->get('/ssr-private');

        $response->assertHeader('X-Pwax-SSR', '0');
        $this->assertStringNotContainsString('data-pwax-prerendered', (string) $response->getContent());
        $this->assertStringNotContainsString('id="pwax-state"', (string) $response->getContent());
    }

    public function test_a_spa_only_route_is_not_prerendered(): void
    {
        $response = $this->get('/ssr-spa-only');

        $response->assertHeader('X-Pwax-SSR', '0');
        $this->assertStringNotContainsString('data-pwax-prerendered', (string) $response->getContent());
    }

    public function test_an_excluded_route_is_not_prerendered(): void
    {
        config()->set('pwax.ssr.exclude', ['pages.scoped']);

        $response = $this->get('/ssr-excluded');

        $response->assertHeader('X-Pwax-SSR', '0');
        $this->assertStringNotContainsString('data-pwax-prerendered', (string) $response->getContent());
    }

    public function test_a_runtime_fetch_still_returns_json_not_prerendered_html(): void
    {
        $response = $this->get('/ssr-home', $this->componentHeaders());

        // The runtime fetch (X-Pwax-Component) gets the JSON payload, never the shell —
        // prerendered or not. This is the content-negotiation guarantee.
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertStringNotContainsString('<h1>Home</h1>', (string) $response->getContent());
    }

    public function test_a_node_failure_falls_back_to_the_spa_shell(): void
    {
        // Point at a nonexistent Node binary. The Prerenderer should catch the failure
        // and fall back to the normal SPA shell rather than 500ing.
        config()->set('pwax.ssr.node', '/nonexistent-node-binary');

        $response = $this->get('/ssr-home');

        $response->assertOk();
        $response->assertHeader('X-Pwax-SSR', '0');
        $this->assertStringNotContainsString('data-pwax-prerendered', (string) $response->getContent());
    }

    public function test_a_prerendered_page_has_a_minimal_noscript_block(): void
    {
        $this->requireNode();

        $response = $this->get('/ssr-home');

        $html = (string) $response->getContent();

        // The "This app needs JavaScript" wall is replaced by a minimal hint, because the
        // content is already visible.
        $this->assertStringNotContainsString('This app needs JavaScript', $html);
        $this->assertStringContainsString('noscript', $html);
    }

    public function test_an_spa_only_page_keeps_the_full_noscript_block(): void
    {
        $response = $this->get('/ssr-spa-only');

        $html = (string) $response->getContent();

        $this->assertStringContainsString('This app needs JavaScript', $html);
    }

    public function test_prerendered_results_are_cached(): void
    {
        $this->requireNode();

        // The component is compiled once per request, not twice. `shell()` used to hand the
        // prerenderer the *response* rather than the component it had just compiled, so the
        // prerenderer asked for it again — and compiling means rendering the Blade view,
        // because the compile cache is keyed on the rendered output.
        $renders = 0;

        View::composer('pages.home', function () use (&$renders): void {
            $renders++;
        });

        $first = $this->get('/ssr-home');

        $first->assertOk();
        $first->assertHeader('X-Pwax-SSR', '1');
        $this->assertSame(1, $renders, 'the page view is rendered once per request');

        // Second request: served from the prerender cache, and still correct.
        $second = $this->get('/ssr-home');

        $second->assertOk();
        $second->assertHeader('X-Pwax-SSR', '1');
        $this->assertStringContainsString('<h1>Home</h1>', (string) $second->getContent());
    }

    public function test_prerenderable_prerenders_a_page_that_cacheable_would_have_excluded(): void
    {
        $this->requireNode();

        // Without `->prerenderable()` this page is skipped: it carries controller data and
        // has not called `cacheable()`. The method used to be incapable of saying otherwise
        // — not opting out was already the default, so it could only ever be a no-op.
        $response = $this->get('/ssr-forced');

        $response->assertOk();
        $response->assertHeader('X-Pwax-SSR', '1');
        $this->assertStringContainsString('Hello Grace', (string) $response->getContent());
    }

    public function test_prerendered_content_is_not_hidden_behind_the_preloader(): void
    {
        $this->requireNode();

        $html = (string) $this->get('/ssr-home')->getContent();

        // `.pwax-preloader::before` is an opaque cover with `inset: 0` and `z-index: 9999`.
        // Left on the mount element it hid the prerendered page until the runtime booted,
        // and hid it from a visitor without JavaScript for good — who is the one the
        // prerender exists for.
        $this->assertMatchesRegularExpression('/<div id="pwax"(?![^>]*pwax-preloader)[^>]*>/', $html);
        $this->assertStringContainsString('data-pwax-prerendered', $html);
    }

    public function test_a_prerendered_page_carries_its_stylesheet_in_the_document(): void
    {
        $this->requireNode();

        // `pages.home` carries a `<style>` block, so there is something to inline.
        $html = (string) $this->get('/ssr-home')->getContent();

        // Otherwise the markup is painted unstyled and restyled once JavaScript runs — and
        // never styled at all without it. `data-pwax-style` is the key the runtime's style
        // manager uses, so it adopts this block rather than appending a second copy.
        $this->assertStringContainsString('data-pwax-style="pwax:page"', $html);
    }

    public function test_an_spa_page_does_not_inline_its_stylesheet(): void
    {
        // The same view, not prerendered. The SPA path is unchanged: the runtime attaches
        // the stylesheet as it mounts, so inlining it here would be dead weight in every
        // document.
        $html = (string) $this->get('/ssr-spa-only')->getContent();

        $this->assertStringNotContainsString('data-pwax-style="pwax:page"', $html);
    }

    public function test_the_state_is_not_shipped_twice(): void
    {
        $this->requireNode();

        $html = (string) $this->get('/ssr-home')->getContent();

        preg_match('/id="pwax-initial"[^>]*>(.*?)<\/script>/s', $html, $matches);

        $payload = json_decode((string) $matches[1], true);

        // The `pwax-state` island is where the runtime reads it and what a crawler can see.
        // The initial payload carries the flag, not a second copy of the state.
        $this->assertTrue($payload['hydrate'] ?? false);
        $this->assertArrayNotHasKey('state', $payload);
    }

    public function test_an_inlined_stylesheet_cannot_break_out_of_its_style_block(): void
    {
        $this->requireNode();

        $html = (string) $this->get('/ssr-style-escape')->getContent();

        // The runtime hands this CSS to the DOM as `textContent`, which cannot terminate
        // the element it is in; writing it into the document as markup can. `</style/` is
        // an end tag to an HTML parser and is *not* matched by the extractor's
        // `<\/style\s*>` closing pattern, so it reaches the document intact.
        $this->assertStringNotContainsString('</style/><b>broken</b>', $html);
        $this->assertStringContainsString('<\\/style/><b>broken</b>', $html);

        // And the real closing tag is still where it belongs: the markup after it parses
        // as markup, not as leftover CSS.
        $this->assertStringContainsString('data-pwax-style="pwax:page"', $html);
    }

    public function test_the_configured_cache_ttl_is_applied(): void
    {
        $this->requireNode();

        config()->set('pwax.ssr.cache.ttl', 60);

        $cache = $this->app->make('cache')->store();

        $this->get('/ssr-home')->assertHeader('X-Pwax-SSR', '1');

        // `ssr.cache.ttl` was documented from the start and read by nothing, so a prerender
        // was kept forever whatever the application asked for.
        $keys = array_keys($this->arrayCacheEntries($cache));
        $ssrKeys = array_values(array_filter($keys, fn ($key) => str_starts_with((string) $key, 'pwax:ssr:')));

        $this->assertNotEmpty($ssrKeys, 'the prerender was cached');

        $entry = $this->arrayCacheEntries($cache)[$ssrKeys[0]];

        // The array store records an absolute expiry; anything finite proves the TTL was
        // passed through rather than the entry being stored forever.
        $this->assertNotSame(0, $entry['expiresAt'] ?? 0);
        $this->assertLessThanOrEqual(time() + 60, (int) ($entry['expiresAt'] ?? 0));
    }

    /**
     * The array cache driver's entries, which it keeps in a private property.
     *
     * @return array<string, array{value: mixed, expiresAt: float|int}>
     */
    private function arrayCacheEntries(mixed $cache): array
    {
        $store = $cache->getStore();
        $property = new \ReflectionProperty($store, 'storage');

        /** @var array<string, array{value: mixed, expiresAt: float|int}> $entries */
        $entries = $property->getValue($store);

        return $entries;
    }

    public function test_ssr_is_off_by_default(): void
    {
        config()->set('pwax.ssr.enabled', false);

        $response = $this->get('/ssr-home');

        $response->assertHeader('X-Pwax-SSR', '0');
        $this->assertStringNotContainsString('data-pwax-prerendered', (string) $response->getContent());
        $this->assertStringNotContainsString('id="pwax-state"', (string) $response->getContent());
    }
}
