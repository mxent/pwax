<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwa\AssetManifest;
use Mxent\Pwax\Tests\TestCase;

/**
 * One vocabulary, and only one.
 *
 * Four config keys answer "when do we go to the network?" — `runtime_strategy`,
 * `navigation_strategy`, `pages.strategy` and each data group's `strategy` — and they all
 * answer it in the same four words. Anything else is a mistake.
 *
 * A mistake has to be *told*, not absorbed. Falling back to a default at the point of use
 * is right for serving a page and useless for finding out why the page is served that way,
 * so the manifest normalises and `pwax:doctor` fails.
 */
class StrategyNamesTest extends TestCase
{
    private function manifest(): array
    {
        config()->set('pwax.service_worker.enabled', true);

        return $this->app->make(AssetManifest::class)->build();
    }

    public function test_an_unrecognised_page_spelling_falls_back_to_the_default(): void
    {
        config()->set('pwax.service_worker.pages.strategy', 'performance');

        // The package default applies — which is exactly what the doctor check below
        // exists to make visible, because nothing about the served page says so.
        $this->assertSame('network-first', $this->manifest()['pageDefaults']['strategy']);
    }

    public function test_an_unrecognised_navigation_spelling_falls_back_to_the_default(): void
    {
        config()->set('pwax.service_worker.navigation_strategy', 'app-shell');

        $this->assertSame('network-first', $this->manifest()['navigationStrategy']);
    }

    public function test_an_unrecognised_data_group_spelling_falls_back_to_the_default(): void
    {
        config()->set('pwax.service_worker.data_groups', [
            ['name' => 'wrong', 'urls' => ['/api/a'], 'strategy' => 'performance'],
            ['name' => 'right', 'urls' => ['/api/b'], 'strategy' => 'cache-first'],
        ]);

        $groups = $this->manifest()['dataGroups'];

        $this->assertNotSame('cache-first', $groups[0]['cacheConfig']['strategy']);
        $this->assertSame('cache-first', $groups[1]['cacheConfig']['strategy']);
    }

    public function test_the_four_spellings_resolve(): void
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
        // name it does not know can never reach the branch that compares against them.
        $json = json_encode($this->manifest());

        $this->assertStringNotContainsString('freshness', $json);
        $this->assertStringNotContainsString('performance', $json);
        $this->assertStringNotContainsString('app-shell', $json);
    }

    public function test_the_doctor_fails_on_every_unrecognised_spelling(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.pages.strategy', 'freshness');
        config()->set('pwax.service_worker.navigation_strategy', 'app-shell');
        config()->set('pwax.service_worker.data_groups', [
            ['name' => 'posts', 'urls' => ['/api/posts'], 'strategy' => 'performance'],
        ]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('pages.strategy has an unknown strategy')
            ->expectsOutputToContain('navigation_strategy has an unknown strategy')
            ->expectsOutputToContain('Data group "posts" has an unknown strategy')
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

    /**
     * A value that is not a string at all.
     *
     * `'strategy' => ['cache-first']` is an ordinary slip — a data group's other keys take
     * arrays. It is still wrong, and reporting it must not itself emit a PHP warning from
     * casting an array to a string in the middle of the message.
     */
    public function test_a_non_string_strategy_is_reported_without_a_php_warning(): void
    {
        config()->set('pwax.service_worker.pages.strategy', ['cache-first']);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('pages.strategy has an unknown strategy "array"')
            ->assertFailed()
            ->run();
    }

    public function test_an_unset_strategy_is_not_reported(): void
    {
        // Every strategy key has a default, so "not set" is not a mistake and must not be
        // dressed up as one — a doctor that fails a stock config is a doctor nobody runs.
        config()->set('pwax.service_worker.pages.strategy', null);
        config()->set('pwax.service_worker.navigation_strategy', '');

        $this->artisan('pwax:doctor')
            ->doesntExpectOutputToContain('has an unknown strategy')
            ->run();
    }
}
