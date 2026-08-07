<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

class PwaEndpointsTest extends TestCase
{
    public function test_serves_the_web_app_manifest(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/manifest+json');
        $response->assertJsonStructure(['name', 'short_name', 'start_url', 'display']);
    }

    public function test_the_manifest_omits_empty_fields(): void
    {
        // A manifest with `"description": null` is invalid; leaving the key out is not.
        $this->assertArrayNotHasKey('description', $this->get('/manifest.webmanifest')->json());
    }

    public function test_the_manifest_is_cacheable_and_conditional(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $this->assertStringContainsString('max-age', (string) $response->headers->get('Cache-Control'));

        $this->get('/manifest.webmanifest', ['If-None-Match' => $response->headers->get('ETag')])
            ->assertStatus(304);
    }

    public function test_the_manifest_reflects_configuration(): void
    {
        config()->set('pwax.manifest.name', 'Configured App');

        $this->assertSame('Configured App', $this->get('/manifest.webmanifest')->json('name'));
    }

    public function test_the_service_worker_is_off_by_default(): void
    {
        $this->get('/service-worker.js')->assertStatus(404);
    }

    public function test_serves_the_service_worker_when_enabled(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $response = $this->get('/service-worker.js');

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
        config()->set('pwax.service_worker.precache', ['/', '/offline']);

        $body = $this->get('/service-worker.js')->getContent();

        $this->assertStringContainsString('"v9"', $body);
        $this->assertStringContainsString('"/offline"', $body);
    }

    public function test_the_service_worker_handles_updates_and_bounds_its_cache(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $body = $this->get('/service-worker.js')->getContent();

        $this->assertStringContainsString('PWAX_SKIP_WAITING', $body);
        $this->assertStringContainsString('MAX_ENTRIES', $body);
        // Only our own caches may be deleted; other libraries own caches on this origin too.
        $this->assertStringContainsString('key.startsWith(`${PREFIX}-`)', $body);
    }

    public function test_static_endpoints_do_not_start_a_session(): void
    {
        // 1.x put the manifest behind the `web` group, which set a session cookie on
        // every fetch of a file that is identical for every visitor.
        $response = $this->get('/manifest.webmanifest');

        $this->assertEmpty(
            array_filter(
                $response->headers->getCookies(),
                static fn ($cookie): bool => str_contains($cookie->getName(), 'session')
            )
        );
    }
}
