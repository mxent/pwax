<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwa\AssetManifest;
use Mxent\Pwax\Support\Shell;
use Mxent\Pwax\Tests\TestCase;

/**
 * One vocabulary, and the old spellings still working.
 *
 * Four config keys answered "when do we go to the network?" in three different languages,
 * and a fifth called `strategy` chose a hostname. Merging them is a readability change, so
 * it must not be a breaking one: every retired name still resolves, and `pwax:doctor` is
 * what tells you to move on.
 */
class StrategyNamesTest extends TestCase
{
    private function manifest(): array
    {
        config()->set('pwax.service_worker.enabled', true);

        return $this->app->make(AssetManifest::class)->build();
    }

    public function test_freshness_still_means_network_first(): void
    {
        config()->set('pwax.service_worker.pages.strategy', 'freshness');

        $this->assertSame('network-first', $this->manifest()['pageDefaults']['strategy']);
    }

    public function test_performance_still_means_cache_first(): void
    {
        config()->set('pwax.service_worker.pages.strategy', 'performance');

        $this->assertSame('cache-first', $this->manifest()['pageDefaults']['strategy']);
    }

    public function test_app_shell_still_means_cache_first(): void
    {
        config()->set('pwax.service_worker.navigation_strategy', 'app-shell');

        $this->assertSame('cache-first', $this->manifest()['navigationStrategy']);
    }

    public function test_a_data_group_accepts_either_spelling(): void
    {
        config()->set('pwax.service_worker.data_groups', [
            ['name' => 'old', 'urls' => ['/api/a'], 'strategy' => 'performance'],
            ['name' => 'new', 'urls' => ['/api/b'], 'strategy' => 'cache-first'],
        ]);

        $groups = $this->manifest()['dataGroups'];

        $this->assertSame('cache-first', $groups[0]['cacheConfig']['strategy']);
        $this->assertSame('cache-first', $groups[1]['cacheConfig']['strategy']);
    }

    public function test_an_unrecognised_name_falls_back_rather_than_breaking_the_worker(): void
    {
        config()->set('pwax.service_worker.runtime_strategy', 'whatever');

        $this->assertSame('network-only', $this->manifest()['strategy']);
    }

    public function test_the_manifest_only_ever_carries_the_new_vocabulary(): void
    {
        config()->set('pwax.service_worker.pages.strategy', 'freshness');
        config()->set('pwax.service_worker.navigation_strategy', 'app-shell');
        config()->set('pwax.service_worker.data_groups', [
            ['name' => 'posts', 'urls' => ['/api/posts'], 'strategy' => 'performance'],
        ]);

        // The point of normalising server-side: the worker knows one set of words, so a
        // retired spelling can never reach the branch that compares against them.
        $json = json_encode($this->manifest());

        $this->assertStringNotContainsString('freshness', $json);
        $this->assertStringNotContainsString('performance', $json);
        $this->assertStringNotContainsString('app-shell', $json);
    }

    public function test_the_asset_source_accepts_the_old_key(): void
    {
        // What an application that published its config before 4.1 actually looks like:
        // `assets` replaces the package default wholesale, so there is no `source` key.
        config()->set('pwax.assets', [
            'strategy' => 'cdn',
            'local_path' => '/vendor/pwax',
            'versions' => [],
            'cdn' => ['base' => 'https://cdn.test', 'integrity' => []],
            'pinia' => true,
        ]);

        $this->assertSame('cdn', $this->app->make(Shell::class)->assetSource());
    }

    public function test_the_new_asset_key_wins_when_both_are_present(): void
    {
        config()->set('pwax.assets.strategy', 'cdn');
        config()->set('pwax.assets.source', 'local');

        $this->assertSame('local', $this->app->make(Shell::class)->assetSource());
    }

    public function test_the_doctor_names_every_retired_spelling(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.pages.strategy', 'freshness');
        config()->set('pwax.service_worker.navigation_strategy', 'app-shell');
        config()->set('pwax.service_worker.data_groups', [
            ['name' => 'posts', 'urls' => ['/api/posts'], 'strategy' => 'performance'],
        ]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('pages.strategy uses the old strategy name')
            ->expectsOutputToContain('navigation_strategy uses the old strategy name')
            ->expectsOutputToContain('Data group "posts" uses the old strategy name')
            ->run();
    }
}
