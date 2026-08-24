<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Event;
use Mxent\Pwax\Events\ManifestBuilt;
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

        $this->assertContains($this->runtimeUrl(), $urls);
        $this->assertContains('/manifest.json', $urls);
        $this->assertTrue(
            (bool) array_filter($urls, static fn (string $u): bool => str_contains($u, 'vue.global.prod.js')),
            'The Vue build must be precached; without it the app cannot start offline at all.'
        );
    }

    public function test_the_framework_and_the_shell_are_critical(): void
    {
        /** @var list<string> $critical */
        $critical = $this->get('/sw.json')->json('critical');

        $this->assertContains($this->runtimeUrl(), $critical);
        $this->assertContains('/__pwax__/shell', $critical);
    }

    public function test_application_scripts_are_precached_but_not_critical(): void
    {
        config()->set('pwax.scripts', ['/js/analytics.js']);

        $this->assertContains('/js/analytics.js', $this->urls());
        $this->assertNotContains('/js/analytics.js', $this->get('/sw.json')->json('critical'));
    }

    public function test_a_head_script_is_precached_like_any_other(): void
    {
        // The manifest read the *positional* list, so moving a script into the head would
        // have quietly dropped it from the offline install — and the scripts most likely to
        // ask for the head are the ones whose absence is most visible, since that is why
        // they asked. A theme script missing offline is a page that boots in the wrong
        // colours; a CSS engine missing offline is a page with no styles at all.
        config()->set('pwax.scripts', [['src' => '/js/theme.js', 'head' => true]]);

        $this->assertContains('/js/theme.js', $this->urls());
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
        $this->assertContains($this->runtimeUrl(), $urls);
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

    public function test_the_runtime_bundle_is_content_addressed_under_its_fingerprinted_url(): void
    {
        /** @var array<string, string> $hashes */
        $hashes = $this->get('/sw.json')->json('hashTable');

        // The revision is what lets a deploy copy the bundle forward from the previous
        // precache instead of downloading it again. It is keyed by the URL that was
        // registered — the fingerprinted one — so looking it up by the bare route silently
        // yields nothing and costs every visitor the download on every release.
        $this->assertArrayHasKey($this->runtimeUrl(), $hashes);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $hashes[$this->runtimeUrl()]);
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

    /**
     * Every package that calls `loadViewsFrom()` registers a view namespace — Laravel's
     * own exception page renderer among them. Those are not components an application
     * imports, and precaching them both fills the manifest with URLs that cannot render
     * offline and mints a signed, publicly addressable URL for each one.
     */
    public function test_package_view_namespaces_are_not_scanned_by_default(): void
    {
        $this->registerNamespacedView('acme-ui');

        $views = array_column($this->app->make(ComponentRegistry::class)->all(), 'view');

        $this->assertNotContains('acme-ui::components.widget', $views);
        // The application's own views are still found.
        $this->assertContains('components.modal', $views);
    }

    public function test_a_namespace_can_be_opted_into(): void
    {
        $this->registerNamespacedView('acme-ui');

        config()->set('pwax.service_worker.namespaces', ['acme-ui']);

        $this->assertContains(
            'acme-ui::components.widget',
            array_column($this->app->make(ComponentRegistry::class)->all(), 'view')
        );
    }

    public function test_the_packages_own_namespace_is_never_scanned(): void
    {
        // Even asked for explicitly: these are shell fragments and the worker source.
        config()->set('pwax.service_worker.namespaces', ['pwax']);

        $views = array_column($this->app->make(ComponentRegistry::class)->all(), 'view');

        $this->assertSame([], array_filter($views, static fn (string $v): bool => str_starts_with($v, 'pwax::')));
    }

    /**
     * Register a throwaway package view namespace containing one component.
     */
    private function registerNamespacedView(string $namespace): void
    {
        $path = sys_get_temp_dir() . '/pwax-' . $namespace . '-' . getmypid();

        @mkdir($path . '/components', 0o777, true);
        file_put_contents($path . '/components/widget.blade.php', "<template>\n    <b>widget</b>\n</template>\n");

        $this->app->make(ViewFactory::class)->addNamespace($namespace, $path);

        $this->beforeApplicationDestroyed(function () use ($path): void {
            @unlink($path . '/components/widget.blade.php');
            @rmdir($path . '/components');
            @rmdir($path);
        });
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
     * The event fires on a real build, carrying the manifest hash and any warnings. It
     * is a reasonable place to push the hash into a deploy log or fail a pipeline when
     * `warnings` is not empty — the same list `pwax:precache` prints.
     */
    public function test_the_manifest_built_event_fires_on_a_real_build(): void
    {
        Event::fake([ManifestBuilt::class]);

        /** @var AssetManifest $manifest */
        $manifest = $this->app->make(AssetManifest::class);
        $built = $manifest->build();

        Event::assertDispatched(ManifestBuilt::class, function (ManifestBuilt $event) use ($built): bool {
            return $event->hash() === $built['hash']
                && $event->warnings() === ($built['warnings'] ?? []);
        });
    }

    /**
     * A memo hit must not re-fire the event — the manifest was already built, and
     * counting it again would make a cache hit look like a build.
     */
    public function test_the_manifest_built_event_does_not_fire_on_a_memo_hit(): void
    {
        // A positive TTL so `get()` memoises rather than building every time.
        config()->set('pwax.service_worker.asset_manifest.ttl', 600);

        /** @var AssetManifest $manifest */
        $manifest = $this->app->make(AssetManifest::class);

        // First call builds and fires the event.
        $manifest->get();

        Event::fake([ManifestBuilt::class]);

        // Second call is a memo hit.
        $manifest->get();

        Event::assertNotDispatched(ManifestBuilt::class);
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
