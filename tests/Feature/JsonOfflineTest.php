<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Support\Shell;
use Mxent\Pwax\Tests\TestCase;

/**
 * The renderer, offline.
 *
 * `<PwaxJson>` fetches `dist/pwax-json.js` on its first render, which is lazy on purpose
 * and is exactly what makes it fail offline if nothing else intervenes: a page marked
 * `->cacheable()` is on disk, opens with the network off, and renders an empty box where
 * its document should be, because the one asset it still needs was never precached.
 *
 * So the bundle is precached, and critical — an install that cannot fetch it has not
 * installed the application. And because the catalog decides which components a document
 * may name, changing it has to invalidate the manifest.
 */
class JsonOfflineTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pwax.service_worker.enabled', true);
        // Rebuilt on every call: these tests change configuration between assertions.
        $app['config']->set('pwax.service_worker.asset_manifest.ttl', 0);
    }

    public function test_the_renderer_is_precached(): void
    {
        $this->assertContains($this->jsonUrl(), $this->urls());
    }

    public function test_the_renderer_is_critical_so_a_half_installed_app_is_not_reported_installed(): void
    {
        $this->assertContains($this->jsonUrl(), (array) $this->get('/sw.json')->json('critical'));
    }

    public function test_the_precached_entry_carries_a_revision(): void
    {
        // Indexed rather than reached with a dotted path: the key is a URL, and both the
        // `.js` and the `?v=` in it are meaningful to `data_get()` and not to us.
        $table = (array) $this->get('/sw.json')->json('hashTable');

        $this->assertIsString(
            $table[$this->jsonUrl()] ?? null,
            'Without a revision the worker cannot copy the entry forward from the previous '
            . 'precache, so every deploy re-downloads 380 kB that has not changed.'
        );
    }

    public function test_nothing_is_precached_when_the_feature_is_off(): void
    {
        config()->set('pwax.json.enabled', false);

        foreach ($this->urls() as $url) {
            $this->assertStringNotContainsString('pwax-json.js', $url);
        }
    }

    public function test_changing_the_catalog_changes_the_manifest_hash(): void
    {
        $before = $this->get('/sw.json')->json('hash');

        config()->set('pwax.json.components', ['Card' => "@pwaxImport('components.badge')"]);

        $this->assertNotSame(
            $before,
            $this->get('/sw.json')->json('hash'),
            'The catalog decides what a document may render. An installed client that keeps '
            . 'the previous one renders documents against components it no longer has.'
        );
    }

    private function jsonUrl(): string
    {
        return (string) $this->app->make(Shell::class)->jsonRuntimeUrl();
    }

    /** @return list<string> */
    private function urls(): array
    {
        /** @var list<array{urls: list<string>}> $groups */
        $groups = $this->get('/sw.json')->json('assetGroups');

        return array_merge(...array_map(static fn (array $g): array => $g['urls'], $groups));
    }
}
