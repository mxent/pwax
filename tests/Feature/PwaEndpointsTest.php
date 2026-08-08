<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

class PwaEndpointsTest extends TestCase
{
    public function test_serves_the_web_app_manifest(): void
    {
        $response = $this->get('/manifest.json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/manifest+json');
        $response->assertJsonStructure(['name', 'short_name', 'start_url', 'display']);
    }

    public function test_the_manifest_omits_empty_fields(): void
    {
        // A manifest with `"description": null` is invalid; leaving the key out is not.
        $this->assertArrayNotHasKey('description', $this->get('/manifest.json')->json());
    }

    public function test_the_manifest_is_cacheable_and_conditional(): void
    {
        $response = $this->get('/manifest.json');

        $this->assertStringContainsString('max-age', (string) $response->headers->get('Cache-Control'));

        $this->get('/manifest.json', ['If-None-Match' => $response->headers->get('ETag')])
            ->assertStatus(304);
    }

    public function test_the_manifest_reflects_configuration(): void
    {
        config()->set('pwax.manifest.name', 'Configured App');

        $this->assertSame('Configured App', $this->get('/manifest.json')->json('name'));
    }

    public function test_the_service_worker_is_off_by_default(): void
    {
        $this->get('/sw.js')->assertStatus(404);
    }

    public function test_serves_the_service_worker_when_enabled(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $response = $this->get('/sw.js');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        // A worker may only control paths at or below its own URL unless it says otherwise.
        $response->assertHeader('Service-Worker-Allowed', '/');
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_service_worker_reflects_configuration(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.version', 'v9');
        config()->set('pwax.service_worker.offline_url', '/offline');

        $body = (string) $this->get('/sw.js')->getContent();

        $this->assertStringContainsString('"v9"', $body);
        $this->assertStringContainsString('"/offline"', $body);
    }

    public function test_the_service_worker_carries_the_manifest_hash(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $body = (string) $this->get('/sw.js')->getContent();
        $hash = (string) $this->get('/sw.json')->json('hash');

        // A browser only installs a worker whose bytes differ from the one it has. Without
        // the hash in the source, a deploy that changed only components would never reach
        // an existing install.
        $this->assertNotSame('', $hash);
        $this->assertStringContainsString($hash, $body);
    }

    public function test_the_service_worker_does_not_skip_waiting_on_its_own(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $body = (string) $this->get('/sw.js')->getContent();

        // `skipWaiting()` during install activates the new worker immediately, which
        // reloads every open tab mid-session and makes the update prompt unobservable.
        // The one permitted call is the one the page asks for.
        $this->assertSame(1, substr_count($body, 'self.skipWaiting()'));
        $this->assertStringContainsString('PWAX_SKIP_WAITING', $body);
    }

    public function test_the_service_worker_handles_updates_and_bounds_its_cache(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $body = (string) $this->get('/sw.js')->getContent();

        $this->assertStringContainsString('PWAX_SKIP_WAITING', $body);
        $this->assertStringContainsString('maxEntries', $body);
        // Only our own caches may be deleted; other libraries own caches on this origin too.
        $this->assertStringContainsString('key.startsWith(`${PREFIX}-`)', $body);
    }

    public function test_the_service_worker_refuses_to_store_no_store_responses(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $body = (string) $this->get('/sw.js')->getContent();

        // The Cache API ignores HTTP cache directives, so a worker that caches whatever it
        // fetches persists signed-in users' pages to disk for the next person to use the
        // device. Honouring `no-store` is what keeps them off it.
        $this->assertStringContainsString('no-store', $body);
    }

    public function test_the_service_worker_is_conditional(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $response = $this->get('/sw.js');

        $this->get('/sw.js', ['If-None-Match' => $response->headers->get('ETag')])
            ->assertStatus(304);
    }

    public function test_static_endpoints_do_not_start_a_session(): void
    {
        // 1.x put the manifest behind the `web` group, which set a session cookie on
        // every fetch of a file that is identical for every visitor.
        $response = $this->get('/manifest.json');

        $this->assertEmpty(
            array_filter(
                $response->headers->getCookies(),
                static fn ($cookie): bool => str_contains($cookie->getName(), 'session')
            )
        );
    }
}
