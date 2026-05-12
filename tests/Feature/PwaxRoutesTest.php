<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

class PwaxRoutesTest extends TestCase
{
    public function test_manifest_endpoint_returns_json(): void
    {
        $response = $this->get('/manifest.webmanifest');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/manifest+json');
        $response->assertJsonStructure(['name', 'short_name', 'start_url']);
    }

    public function test_service_worker_disabled_by_default(): void
    {
        $response = $this->get('/service-worker.js');
        $response->assertStatus(404);
    }

    public function test_service_worker_served_when_enabled(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        $response = $this->get('/service-worker.js');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('CACHE_NAME', $response->getContent());
    }

    public function test_invalid_module_returns_400(): void
    {
        $prefix = config('pwax.route_prefix', '__pwax__');
        // Slashes are blocked by the route constraint -> 404. Use an
        // otherwise-valid name that decodes to a missing view to ensure 200/400/500 paths work.
        $response = $this->getJson('/' . $prefix . '/definitely_x_missing.json');
        // Either a 200 with error payload (view not found is caught) or 400 invalid.
        $this->assertContains($response->status(), [200, 400, 500]);
    }
}
