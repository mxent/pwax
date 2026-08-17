<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwa\AssetManifest;
use Mxent\Pwax\Support\Shell;
use Mxent\Pwax\Tests\TestCase;

/**
 * One vocabulary, and only one.
 *
 * Four config keys answered "when do we go to the network?" in three different languages,
 * and a fifth called `strategy` chose a hostname. 4.x merged them and kept the retired
 * spellings — `freshness`, `performance`, `app-shell`, `assets.strategy` — working as
 * aliases so the readability change was not a breaking one. 5.0 removes them.
 *
 * These tests are the other half of that removal. A clean break is only clean if the
 * application is *told*: an alias that silently falls back to a default is worse than the
 * alias, because the config still reads as though it configures something. So the aliases
 * no longer resolve, and `pwax:doctor` fails rather than warns.
 */
class StrategyNamesTest extends TestCase
{
    private function manifest(): array
    {
        config()->set('pwax.service_worker.enabled', true);

        return $this->app->make(AssetManifest::class)->build();
    }

    public function test_a_retired_page_spelling_no_longer_resolves(): void
    {
        config()->set('pwax.service_worker.pages.strategy', 'performance');

        // Not `cache-first`. The alias is gone, so this is an unrecognised value and the
        // package default applies — which is exactly what the doctor check below exists to
        // make visible, because nothing about the served page says so.
        $this->assertSame('network-first', $this->manifest()['pageDefaults']['strategy']);
    }

    public function test_a_retired_navigation_spelling_no_longer_resolves(): void
    {
        config()->set('pwax.service_worker.navigation_strategy', 'app-shell');

        $this->assertSame('network-first', $this->manifest()['navigationStrategy']);
    }

    public function test_a_retired_data_group_spelling_no_longer_resolves(): void
    {
        config()->set('pwax.service_worker.data_groups', [
            ['name' => 'old', 'urls' => ['/api/a'], 'strategy' => 'performance'],
            ['name' => 'new', 'urls' => ['/api/b'], 'strategy' => 'cache-first'],
        ]);

        $groups = $this->manifest()['dataGroups'];

        $this->assertNotSame('cache-first', $groups[0]['cacheConfig']['strategy']);
        $this->assertSame('cache-first', $groups[1]['cacheConfig']['strategy']);
    }

    public function test_the_current_spellings_resolve(): void
    {
        config()->set('pwax.service_worker.pages.strategy', 'cache-first');
        config()->set('pwax.service_worker.navigation_strategy', 'cache-first');
        config()->set('pwax.service_worker.runtime_strategy', 'stale-while-revalidate');

        $manifest = $this->manifest();

        $this->assertSame('cache-first', $manifest['pageDefaults']['strategy']);
        $this->assertSame('cache-first', $manifest['navigationStrategy']);
        $this->assertSame('stale-while-revalidate', $manifest['strategy']);
    }

    public function test_an_unrecognised_name_falls_back_rather_than_breaking_the_worker(): void
    {
        config()->set('pwax.service_worker.runtime_strategy', 'whatever');

        // Falling back is still right at the point of use: a typo in one config key must
        // not take the offline application down with it. The doctor is where it is named.
        $this->assertSame('network-only', $this->manifest()['strategy']);
    }

    public function test_the_manifest_only_ever_carries_the_current_vocabulary(): void
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
        $this->assertStringNotContainsString('"performance"', $json);
        $this->assertStringNotContainsString('app-shell', $json);
    }

    public function test_the_retired_asset_key_is_no_longer_read(): void
    {
        // What an application that published its config before 4.1 looks like: `assets`
        // replaces the package default wholesale, so there is no `source` key at all.
        config()->set('pwax.assets', [
            'strategy' => 'cdn',
            'local_path' => '/vendor/pwax',
            'versions' => [],
            'cdn' => ['base' => 'https://cdn.test', 'integrity' => []],
            'pinia' => true,
        ]);

        // Local, not cdn. This is a real behaviour change on upgrade — which is precisely
        // why the doctor treats a leftover `assets.strategy` as a failure and not a note.
        $this->assertSame('local', $this->app->make(Shell::class)->assetSource());
    }

    public function test_the_doctor_fails_on_every_retired_spelling(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.pages.strategy', 'freshness');
        config()->set('pwax.service_worker.navigation_strategy', 'app-shell');
        config()->set('pwax.assets.strategy', 'cdn');
        config()->set('pwax.service_worker.data_groups', [
            ['name' => 'posts', 'urls' => ['/api/posts'], 'strategy' => 'performance'],
        ]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('pages.strategy has an unknown strategy')
            ->expectsOutputToContain('navigation_strategy has an unknown strategy')
            ->expectsOutputToContain('Data group "posts" has an unknown strategy')
            ->expectsOutputToContain('pwax.assets.strategy was removed in 5.0')
            ->assertFailed()
            ->run();
    }

    public function test_the_doctor_is_quiet_about_a_config_that_uses_the_current_names(): void
    {
        config()->set('pwax.service_worker.pages.strategy', 'cache-first');
        config()->set('pwax.service_worker.navigation_strategy', 'network-first');

        $this->artisan('pwax:doctor')
            ->doesntExpectOutputToContain('has an unknown strategy')
            ->run();
    }
}
