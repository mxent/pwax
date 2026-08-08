<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwa\AssetManifest;
use Mxent\Pwax\Pwa\ComponentRegistry;
use Mxent\Pwax\Tests\TestCase;

/**
 * The asset manifest is what makes "works offline" true rather than aspirational: it is
 * the complete list of URLs the service worker installs the application from. These tests
 * are about that completeness — that every component is in it, that each entry is
 * content-addressed so a deploy busts only what changed, and that nothing user-specific
 * is on the list.
 */
class OfflineManifestTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pwax.service_worker.enabled', true);
        // Rebuilt on every call: these tests change configuration between assertions.
        $app['config']->set('pwax.service_worker.asset_manifest.ttl', 0);
    }

    public function test_the_asset_manifest_is_404_while_the_worker_is_off(): void
    {
        config()->set('pwax.service_worker.enabled', false);

        $this->get('/sw.json')->assertStatus(404);
    }

    public function test_serves_the_asset_manifest(): void
    {
        $response = $this->get('/sw.json');

        $response->assertOk();
        $response->assertJsonStructure(['hash', 'version', 'assetGroups', 'hashTable', 'critical']);
    }

    public function test_the_asset_manifest_is_revalidated_rather_than_cached(): void
    {
        $response = $this->get('/sw.json');

        // This document is how a client discovers a new build, so a stale copy delays
        // every update by however long it was cached for.
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));

        $this->get('/sw.json', ['If-None-Match' => $response->headers->get('ETag')])
            ->assertStatus(304);
    }

    public function test_the_asset_manifest_lists_the_framework_and_the_runtime(): void
    {
        $urls = $this->urls();

        $this->assertContains('/__pwax__/pwax.js', $urls);
        $this->assertContains('/manifest.webmanifest', $urls);
        $this->assertTrue(
            (bool) array_filter($urls, static fn (string $u): bool => str_contains($u, 'vue.global.prod.js')),
            'The Vue build must be precached; without it the app cannot start offline at all.'
        );
    }

    public function test_the_framework_and_the_shell_are_critical(): void
    {
        /** @var list<string> $critical */
        $critical = $this->get('/sw.json')->json('critical');

        $this->assertContains('/__pwax__/pwax.js', $critical);
        $this->assertContains('/__pwax__/shell', $critical);
    }

    public function test_application_scripts_are_precached_but_not_critical(): void
    {
        config()->set('pwax.scripts', ['/js/analytics.js']);

        $this->assertContains('/js/analytics.js', $this->urls());
        $this->assertNotContains('/js/analytics.js', $this->get('/sw.json')->json('critical'));
    }

    public function test_the_asset_manifest_lists_every_component(): void
    {
        $urls = $this->urls();

        foreach (['components.modal', 'pages.home', 'pages.scoped'] as $view) {
            $this->assertContains(
                $this->pwaxUrl($view),
                $urls,
                sprintf('%s is not precached, so it would not be available offline.', $view)
            );
        }
    }

    public function test_components_can_be_narrowed_to_selected_patterns(): void
    {
        config()->set('pwax.service_worker.components', ['components.*']);

        $urls = $this->urls();

        $this->assertContains($this->pwaxUrl('components.modal'), $urls);
        $this->assertNotContains($this->pwaxUrl('pages.home'), $urls);
    }

    public function test_components_can_be_turned_off_entirely(): void
    {
        config()->set('pwax.service_worker.components', false);

        $urls = $this->urls();

        $this->assertNotContains($this->pwaxUrl('components.modal'), $urls);
        // The framework still is: turning components off is not turning offline off.
        $this->assertContains('/__pwax__/pwax.js', $urls);
    }

    public function test_the_allowlist_narrows_precaching_too(): void
    {
        // A view the application refuses to serve has no business in a manifest that
        // tells the browser to go and fetch it.
        config()->set('pwax.components.allowed', ['components.*']);

        $this->assertNotContains($this->pwaxUrl('pages.home'), $this->urls());
    }

    public function test_every_component_entry_is_content_addressed(): void
    {
        /** @var array<string, string> $hashes */
        $hashes = $this->get('/sw.json')->json('hashTable');

        $this->assertArrayHasKey($this->pwaxUrl('components.modal'), $hashes);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $hashes[$this->pwaxUrl('components.modal')]);
    }

    public function test_the_manifest_hash_changes_when_a_component_changes(): void
    {
        $before = $this->get('/sw.json')->json('hash');

        $path = __DIR__ . '/../fixtures/views/components/scratch.blade.php';
        file_put_contents($path, "<template>\n    <p>one</p>\n</template>\n");

        try {
            $first = $this->get('/sw.json')->json('hash');
            $this->assertNotSame($before, $first, 'Adding a component must produce a new manifest.');

            file_put_contents($path, "<template>\n    <p>two</p>\n</template>\n");

            $this->assertNotSame(
                $first,
                $this->get('/sw.json')->json('hash'),
                'Editing a component must bust its cache entry without a manual version bump.'
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_the_manifest_is_deterministic(): void
    {
        // A manifest that differed run to run would rename the cache on every request and
        // re-download the whole application each time.
        $this->assertSame(
            $this->get('/sw.json')->getContent(),
            $this->get('/sw.json')->getContent()
        );
    }

    public function test_bumping_the_version_busts_everything(): void
    {
        $before = $this->get('/sw.json')->json('hash');

        config()->set('pwax.service_worker.version', 'v2');

        $this->assertNotSame($before, $this->get('/sw.json')->json('hash'));
    }

    public function test_the_registry_ignores_views_that_are_not_components(): void
    {
        /** @var ComponentRegistry $registry */
        $registry = $this->app->make(ComponentRegistry::class);

        $views = array_column($registry->all(), 'view');

        $this->assertContains('components.modal', $views);
        // The package's own shell and partials are views, not components.
        $this->assertNotContains('pwax::layouts.shell', $views);
    }

    public function test_the_registry_finds_script_only_components(): void
    {
        $path = __DIR__ . '/../fixtures/views/middleware';
        @mkdir($path, 0o777, true);
        file_put_contents($path . '/confirmed.blade.php', "<script>\n    export default async function () {};\n</script>\n");

        try {
            /** @var ComponentRegistry $registry */
            $registry = $this->app->make(ComponentRegistry::class);

            // Plugins, directives and client middleware have no template at all.
            $this->assertContains('middleware.confirmed', array_column($registry->all(), 'view'));
        } finally {
            @unlink($path . '/confirmed.blade.php');
            @rmdir($path);
        }
    }

    public function test_flushing_rebuilds_the_manifest(): void
    {
        config()->set('pwax.service_worker.asset_manifest.ttl', 600);

        /** @var AssetManifest $manifest */
        $manifest = $this->app->make(AssetManifest::class);

        $before = $manifest->get()['hash'];

        config()->set('pwax.service_worker.version', 'v3');

        $this->assertSame($before, $manifest->get()['hash'], 'The manifest is memoised.');

        $manifest->flush();

        $this->assertNotSame($before, $manifest->get()['hash']);
    }

    /**
     * Every URL the worker is told to prefetch.
     *
     * @return list<string>
     */
    private function urls(): array
    {
        /** @var list<array{urls: list<string>}> $groups */
        $groups = $this->get('/sw.json')->json('assetGroups');

        return array_merge(...array_map(static fn (array $g): array => $g['urls'], $groups));
    }

    private function pwaxUrl(string $view): string
    {
        return '/__pwax__/c/' . $this->id($view) . '.js';
    }
}
