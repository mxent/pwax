<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwax;
use Mxent\Pwax\Tests\TestCase;

/**
 * The server half of making pages available offline.
 *
 * The client half lives in `tests/js/serviceWorkerPages.test.js`. What is asserted here is
 * the contract between them: the manifest tells the worker which routes are pages and
 * exactly which headers to ask for them with, and if those headers ever stop matching
 * `Pwax::VARY` every page lookup in every browser starts missing — silently, and with
 * nothing in either codebase to point at.
 */
class OfflinePagesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pwax.service_worker.enabled', true);
        $app['config']->set('pwax.service_worker.asset_manifest.ttl', 0);
        $app['config']->set('pwax.service_worker.pages.urls', ['/about', '/pricing']);
    }

    /**
     * The assertion that stops the two sides drifting apart.
     *
     * A page response varies on these three headers. The worker fetches with them and
     * keys its cache on them. If someone adds a field to `Pwax::VARY` without adding it
     * here, nothing fails loudly — the cache simply never matches again.
     */
    public function test_the_page_headers_are_exactly_the_fields_the_response_varies_on(): void
    {
        /** @var array<string, string> $headers */
        $headers = $this->get('/sw.json')->json('pageHeaders');

        $varied = array_map(trim(...), explode(',', Pwax::VARY));

        sort($varied);

        $sent = array_keys($headers);
        sort($sent);

        $this->assertSame($varied, $sent);
    }

    public function test_configured_routes_become_a_page_group(): void
    {
        $group = $this->pageGroup();

        $this->assertSame('page', $group['kind']);
        $this->assertContains('/about', $group['urls']);
        $this->assertContains('/pricing', $group['urls']);
    }

    /**
     * Cookies are passed through by default. Caches are shared across visitors, so what
     * is stored is the response the server returned for whoever fetched the URL — which
     * for a page that renders the same for everyone is irrelevant, and for a page that
     * does not is what `->offline(false)` exists to refuse.
     */
    public function test_pages_are_fetched_with_cookies_by_default(): void
    {
        $this->assertSame('include', $this->pageGroup()['credentials']);
    }

    public function test_pages_declare_their_strategy_and_timeout(): void
    {
        config()->set('pwax.service_worker.pages.strategy', 'cache-first');
        config()->set('pwax.service_worker.pages.timeout', 750);

        $group = $this->pageGroup();

        $this->assertSame('cache-first', $group['strategy']);
        $this->assertSame(750, $group['timeout']);
    }

    /**
     * Page URLs are rendered, not files, so there is nothing on disk to digest. Putting a
     * hash against one would claim the previous cache's copy could be reused across a
     * deploy, which for something the server renders per request is not true.
     */
    public function test_page_urls_are_not_content_hashed(): void
    {
        /** @var array<string, string> $hashes */
        $hashes = $this->get('/sw.json')->json('hashTable');

        $this->assertArrayNotHasKey('/about', $hashes);
    }

    public function test_runtime_page_caching_can_be_turned_off(): void
    {
        $this->assertTrue($this->get('/sw.json')->json('pageRuntime'));

        config()->set('pwax.service_worker.pages.runtime', false);

        $this->assertFalse($this->get('/sw.json')->json('pageRuntime'));
    }

    public function test_the_navigation_strategy_and_urls_reach_the_manifest(): void
    {
        config()->set('pwax.service_worker.navigation_strategy', 'cache-first');

        $manifest = $this->get('/sw.json')->json();

        $this->assertSame('cache-first', $manifest['navigationStrategy']);

        // Compiled to regexes server-side so the worker needs no glob implementation.
        $this->assertNotEmpty($manifest['navigationUrls']);
        $this->assertStringStartsWith('^', $manifest['navigationUrls'][0]);
    }

    public function test_an_unknown_navigation_strategy_falls_back_rather_than_breaking(): void
    {
        config()->set('pwax.service_worker.navigation_strategy', 'nonsense');

        $this->assertSame('network-first', $this->get('/sw.json')->json('navigationStrategy'));
    }

    public function test_data_groups_are_compiled_into_the_manifest(): void
    {
        config()->set('pwax.service_worker.data_groups', [[
            'name' => 'posts',
            'urls' => ['/api/posts', '/api/posts/**'],
            'strategy' => 'cache-first',
            'max_age' => 60,
            'max_entries' => 10,
        ]]);

        /** @var list<array<string, mixed>> $groups */
        $groups = $this->get('/sw.json')->json('dataGroups');

        $this->assertCount(1, $groups);
        $this->assertSame('posts', $groups[0]['name']);
        $this->assertSame('cache-first', $groups[0]['cacheConfig']['strategy']);
        $this->assertSame(60, $groups[0]['cacheConfig']['maxAge']);
        $this->assertSame(10, $groups[0]['cacheConfig']['maxSize']);
        $this->assertCount(2, $groups[0]['patterns']);
    }

    public function test_a_data_group_written_the_old_way_is_not_silently_honoured(): void
    {
        // Flat now, like `pages` and `asset_groups`. A group left nested reads as
        // configured and runs on defaults, which is why `pwax:doctor` calls it out —
        // there is nothing here that could detect it at runtime.
        config()->set('pwax.service_worker.data_groups', [[
            'name' => 'posts',
            'urls' => ['/api/posts'],
            'cache_config' => ['strategy' => 'cache-first', 'max_size' => 10],
        ]]);

        /** @var list<array<string, mixed>> $groups */
        $groups = $this->get('/sw.json')->json('dataGroups');

        $this->assertSame('network-first', $groups[0]['cacheConfig']['strategy']);
        $this->assertSame(50, $groups[0]['cacheConfig']['maxSize']);
    }

    public function test_a_data_group_with_no_urls_is_ignored(): void
    {
        config()->set('pwax.service_worker.data_groups', [['name' => 'empty', 'urls' => []]]);

        $this->assertSame([], $this->get('/sw.json')->json('dataGroups'));
    }

    /**
     * The one page group in the manifest.
     *
     * @return array<string, mixed>
     */
    private function pageGroup(): array
    {
        /** @var list<array<string, mixed>> $groups */
        $groups = $this->get('/sw.json')->json('assetGroups');

        foreach ($groups as $group) {
            if (($group['kind'] ?? null) === 'page') {
                return $group;
            }
        }

        $this->fail('The manifest declares no page group.');
    }
}
