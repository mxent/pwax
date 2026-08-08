<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwa\PageRegistry;
use Mxent\Pwax\Tests\TestCase;

/**
 * Finding the routes that render a page.
 *
 * Caching pages as they are visited only covers where someone has already been: install
 * from the home page, go offline, open Settings, and the one page you needed is the one
 * nobody had opened. Listing every route by hand works and goes stale the first time
 * somebody adds one.
 */
class PageDiscoveryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pwax.service_worker.enabled', true);
        $app['config']->set('pwax.service_worker.asset_manifest.ttl', 0);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/settings', fn () => pwaxRender('pages.home'));
            $router->get('/scoped-page', fn () => pwaxRender('pages.scoped'));

            // Not a page: no render call in the action at all.
            $router->get('/plain', fn () => 'plain');

            // A template, not a page — there is no single URL to precache.
            $router->get('/posts/{post}', fn (string $post) => pwaxRender('pages.home'));

            // Only GET is precacheable.
            $router->post('/submit', fn () => pwaxRender('pages.home'));
        });
    }

    public function test_it_finds_a_route_that_renders_a_page(): void
    {
        $this->assertContains('/settings', $this->discovered());
    }

    public function test_it_ignores_a_route_that_renders_nothing(): void
    {
        $this->assertNotContains('/plain', $this->discovered());
    }

    /**
     * `/posts/{post}` has no single URL to precache. Runtime caching covers the ones a
     * visitor actually opens; a specific value can be listed in `pages.urls`.
     */
    public function test_it_ignores_a_parameterised_route(): void
    {
        $this->assertSame([], array_filter($this->discovered(), fn ($u) => str_contains($u, 'posts')));
    }

    public function test_it_ignores_a_non_get_route(): void
    {
        $this->assertNotContains('/submit', $this->discovered());
    }

    /**
     * Pwax's own endpoints are precached as themselves, not fetched as pages.
     */
    public function test_it_ignores_the_packages_own_routes(): void
    {
        foreach ($this->discovered() as $url) {
            $this->assertStringNotContainsString('__pwax__', $url);
            $this->assertNotSame('/sw.json', $url);
        }
    }

    public function test_discovered_pages_reach_the_manifest(): void
    {
        $this->assertContains('/settings', $this->pageGroupUrls());
    }

    /**
     * The point of reading the view name rather than just the URL: one setting decides
     * what goes offline, for pages and components alike.
     */
    public function test_the_component_selection_scopes_pages_too(): void
    {
        config()->set('pwax.service_worker.components', ['pages.scoped']);

        $urls = $this->discovered();

        $this->assertContains('/scoped-page', $urls);
        $this->assertNotContains('/settings', $urls);
    }

    public function test_turning_components_off_turns_page_discovery_off(): void
    {
        config()->set('pwax.service_worker.components', false);

        $this->assertSame([], $this->discovered());
    }

    public function test_discovery_can_be_turned_off_on_its_own(): void
    {
        config()->set('pwax.service_worker.pages.discover', false);

        $this->assertSame([], $this->discovered());
    }

    /**
     * Discovery adds to the explicit list rather than replacing it — a route it cannot
     * read statically still has somewhere to go.
     */
    public function test_explicitly_listed_pages_survive_discovery(): void
    {
        config()->set('pwax.service_worker.pages.urls', ['/manual']);

        $urls = $this->pageGroupUrls();

        $this->assertContains('/manual', $urls);
        $this->assertContains('/settings', $urls);
    }

    public function test_the_manifest_stays_deterministic_with_discovery_on(): void
    {
        $this->assertSame(
            $this->get('/sw.json')->getContent(),
            $this->get('/sw.json')->getContent()
        );
    }

    /**
     * @return list<string>
     */
    private function discovered(): array
    {
        return array_column($this->app->make(PageRegistry::class)->precachable(), 'url');
    }

    /**
     * @return list<string>
     */
    private function pageGroupUrls(): array
    {
        /** @var list<array<string, mixed>> $groups */
        $groups = $this->get('/sw.json')->json('assetGroups');

        foreach ($groups as $group) {
            if (($group['kind'] ?? null) === 'page') {
                /** @var list<string> $urls */
                $urls = $group['urls'];

                return $urls;
            }
        }

        return [];
    }
}
