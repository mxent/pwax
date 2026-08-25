<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

/**
 * What the server tells the runtime about the back button.
 *
 * A router turns back into an ordinary navigation, so the page a visitor was looking at a
 * moment ago is fetched again. The runtime keeps rendered pages and answers a navigation
 * the browser started from memory instead — but only if the shell says it may, and only
 * up to a cap it is given here.
 *
 * The shape of that message is what these assert. `false` and `['entries' => n]` mean
 * different things to the runtime: the first builds nothing at all, not even the
 * `popstate` listener, while the second is a store with a size.
 */
class BackForwardRestoreTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/page', fn () => pwaxRender('pages.home'));
        });
    }

    public function test_pages_are_kept_for_the_back_button_by_default(): void
    {
        // On by default: the browser does this for a server-rendered site, and an
        // application should not have to opt in to getting it back.
        $this->assertSame(
            ['entries' => 12, 'state' => true],
            $this->runtimeConfig()['restore']
        );
    }

    public function test_the_cap_is_configurable(): void
    {
        config()->set('pwax.restore.entries', 40);

        $this->assertSame(40, $this->runtimeConfig()['restore']['entries']);
    }

    public function test_component_state_can_be_left_behind_while_the_round_trip_is_kept(): void
    {
        config()->set('pwax.restore.state', false);

        // The escape hatch for an application whose pages assume `mounted()` runs on
        // every visit: back is still instant, but the instance is rebuilt.
        $restore = $this->runtimeConfig()['restore'];

        $this->assertFalse($restore['state']);
        $this->assertSame(12, $restore['entries']);
    }

    public function test_state_retention_goes_away_with_restoration_itself(): void
    {
        config()->set('pwax.restore.enabled', false);

        // `<KeepAlive>` cannot work without the stored options object that gives Vue a
        // stable component identity, so there is no such thing as state retention with
        // restoration switched off.
        $this->assertFalse($this->runtimeConfig()['restore']);
    }

    public function test_it_can_be_switched_off_entirely(): void
    {
        config()->set('pwax.restore.enabled', false);

        // `false`, not an empty array: the runtime reads it as "build nothing" — no
        // listener, no store — rather than as settings it should apply.
        $this->assertFalse($this->runtimeConfig()['restore']);
    }

    public function test_a_cap_of_zero_is_off_rather_than_unbounded(): void
    {
        config()->set('pwax.restore.entries', 0);

        // Sent through as `['entries' => 0]` the runtime would listen for every pop in
        // order to answer it from a store that can never hold anything.
        $this->assertFalse($this->runtimeConfig()['restore']);
    }

    public function test_a_negative_cap_is_off_too(): void
    {
        config()->set('pwax.restore.entries', -5);

        $this->assertFalse($this->runtimeConfig()['restore']);
    }

    /**
     * The runtime configuration island, decoded.
     *
     * @return array<string, mixed>
     */
    private function runtimeConfig(): array
    {
        $html = (string) $this->get('/page')->getContent();

        $this->assertSame(
            1,
            preg_match('/<script type="application\/json" id="pwax-config"[^>]*>(.*?)<\/script>/s', $html, $m)
        );

        /** @var array<string, mixed> $config */
        $config = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        return $config;
    }
}
