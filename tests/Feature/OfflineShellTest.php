<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

/**
 * The offline shell is the document a navigation falls back to when the network cannot be
 * reached. It exists so that offline navigation does not require caching real pages: a
 * page rendered by `pwax_component()` carries the signed-in user's data and CSRF token,
 * and the Cache API ignores the `no-store` the server sends with it.
 */
class OfflineShellTest extends TestCase
{
    public function test_serves_the_offline_shell(): void
    {
        $response = $this->get('/__pwax__/shell');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('id="pwax"', false);
    }

    public function test_the_offline_shell_carries_no_csrf_token(): void
    {
        // A token cached on disk and replayed days later is worthless, and storing one
        // user's token for the next user of the device is worse than worthless.
        $this->get('/__pwax__/shell')->assertDontSee('name="csrf-token"', false);
    }

    public function test_the_offline_shell_embeds_no_component(): void
    {
        // `pwax-initial` is the current page's compiled component. The shell is not a
        // page, so there is nothing user-specific in it to embed.
        $this->get('/__pwax__/shell')->assertDontSee('id="pwax-initial"', false);
    }

    public function test_the_offline_shell_boots_the_runtime(): void
    {
        $body = (string) $this->get('/__pwax__/shell')->getContent();

        // Everything needed to start the app and route client-side from a cold start.
        $this->assertStringContainsString('id="pwax-config"', $body);
        $this->assertStringContainsString('/__pwax__/pwax.js', $body);
    }

    public function test_the_offline_shell_is_public_and_conditional(): void
    {
        $response = $this->get('/__pwax__/shell');

        // Public precisely because there is nothing user-specific in it — which is the
        // entire reason this route exists rather than precaching `/`.
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');

        $this->get('/__pwax__/shell', ['If-None-Match' => $response->headers->get('ETag')])
            ->assertStatus(304);
    }

    public function test_the_offline_shell_starts_no_session(): void
    {
        $response = $this->get('/__pwax__/shell');

        $this->assertEmpty(
            array_filter(
                $response->headers->getCookies(),
                static fn ($cookie): bool => str_contains($cookie->getName(), 'session')
            )
        );
    }

    public function test_the_shell_emits_the_tags_ios_needs(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/icons/180.png', 'sizes' => '180x180', 'type' => 'image/png'],
        ]);
        config()->set('pwax.manifest.short_name', 'Ledger');

        $body = (string) $this->get('/__pwax__/shell')->getContent();

        // iOS reads none of the manifest for the home screen.
        $this->assertStringContainsString('rel="apple-touch-icon" href="/icons/180.png"', $body);
        $this->assertStringContainsString('name="apple-mobile-web-app-capable"', $body);
        $this->assertStringContainsString('name="apple-mobile-web-app-title" content="Ledger"', $body);
        $this->assertStringContainsString('rel="manifest"', $body);
    }

    public function test_the_shell_preloads_the_framework(): void
    {
        $body = (string) $this->get('/__pwax__/shell')->getContent();

        // Four blocking scripts discovered one at a time is four serial round trips.
        $this->assertStringContainsString('rel="preload" as="script"', $body);
        $this->assertStringContainsString('href="/vendor/pwax/vue.global.prod.js', $body);
    }

    public function test_the_offline_shell_can_be_turned_off(): void
    {
        config()->set('pwax.service_worker.shell.enabled', false);

        $this->get('/__pwax__/shell')->assertStatus(404);
    }

    public function test_pages_still_carry_a_csrf_token(): void
    {
        // The shell dropping the token must not be mistaken for the token going away.
        $this->get('/component-page')->assertSee('name="csrf-token"', false);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->get('/component-page', fn () => pwax_component('pages.home'));
    }
}
