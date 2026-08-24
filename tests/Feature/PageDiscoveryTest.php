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

            // Branches between two views. One URL, and precachable if either is selected.
            $router->get('/branching', function () {
                return request()->boolean('scoped')
                    ? pwaxRender('pages.scoped')
                    : pwaxRender('pages.home');
            });
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

    /**
     * A controller that branches still answers one URL. Precaching it stores whichever
     * view the server renders for an anonymous request, so any one selected view is
     * enough to include the route.
     */
    public function test_a_route_that_branches_is_kept_if_either_view_is_selected(): void
    {
        config()->set('pwax.service_worker.components', ['pages.scoped']);

        $this->assertContains('/branching', $this->discovered());
    }

    public function test_a_route_that_branches_is_dropped_when_neither_view_is_selected(): void
    {
        config()->set('pwax.service_worker.components', ['components.*']);

        $this->assertNotContains('/branching', $this->discovered());
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

    /**
     * A page's module URL is never fetched to render that page — the payload carries the
     * script inline — so precaching it costs a request for nothing, and for a view that
     * needs controller data it fails with an error naming a URL nothing would ask for.
     */
    public function test_a_page_view_is_not_also_precached_as_a_component(): void
    {
        $this->assertNotContains($this->pwaxUrl('pages.home'), $this->componentGroupUrls());
    }

    public function test_a_page_view_can_be_kept_as_a_component(): void
    {
        config()->set('pwax.service_worker.pages.as_components', true);

        $this->assertContains($this->pwaxUrl('pages.home'), $this->componentGroupUrls());
    }

    /**
     * Only the page views. A component nothing routes to is still precached as a module,
     * which is the whole point of the components group.
     */
    public function test_an_ordinary_component_is_still_precached(): void
    {
        $this->assertContains($this->pwaxUrl('components.modal'), $this->componentGroupUrls());
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

    private function pwaxUrl(string $view): string
    {
        return '/__pwax__/c/' . $this->id($view) . '.js';
    }

    /**
     * @return list<string>
     */
    private function componentGroupUrls(): array
    {
        /** @var list<array<string, mixed>> $groups */
        $groups = $this->get('/sw.json')->json('assetGroups');

        foreach ($groups as $group) {
            if (($group['name'] ?? null) === 'components') {
                /** @var list<string> $urls */
                $urls = $group['urls'];

                return $urls;
            }
        }

        return [];
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
