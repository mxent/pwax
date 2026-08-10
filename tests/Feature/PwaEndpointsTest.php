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

        // The version travels in the banner comment, for log output; cache names are
        // keyed by the manifest hash alone, so version changes do not invalidate them.
        $this->assertStringContainsString('version:  v9', $body);
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
        $this->assertStringContainsString('"maxEntries":', $body);

        // What the worker *does* with these — only deleting its own caches, bounding what
        // it stores — is asserted in tests/js against a fake Cache API. Reading the shipped
        // source for a substring proved nothing once it was minified, and proved little
        // before: it matched the code being present, not the code being reached.
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

    /**
     * Every response from the package carries `X-Content-Type-Options: nosniff`. These
     * endpoints serve JavaScript that a browser will execute on faith; taking sniffing
     * off the table is the cheapest thing that prevents a misconfigured response from
     * being parsed as something it is not.
     */
    public function test_every_endpoint_disables_content_type_sniffing(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $this->get('/manifest.json')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/__pwax__/pwax.js')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/sw.js')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/sw.json')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * A PWA shell is by definition a long-lived document. `no-referrer` is the
     * strictest sane default: a Referer header sent to a third party (a script, an
     * image) leaks the application's URL, and the shell does not need to send anything.
     */
    public function test_every_endpoint_carries_a_referrer_policy(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $this->get('/manifest.json')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/__pwax__/pwax.js')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/sw.js')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * The HTML shell gets `X-Frame-Options: SAMEORIGIN` against clickjacking. Asset
     * responses are inert when framed and do not need it; that is why the hardening
     * differentiates them.
     */
    public function test_the_html_shell_is_protected_against_framing(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $this->get('/__pwax__/shell')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->get('/manifest.json')->assertHeaderMissing('X-Frame-Options');
    }

    /**
     * `Permissions-Policy` shuts off features the application is not asking for. Modern
     * browsers respect it; older ones ignore it.
     */
    public function test_the_html_shell_carries_a_permissions_policy(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $header = (string) $this->get('/__pwax__/shell')->headers->get('Permissions-Policy');

        $this->assertStringContainsString('camera=()', $header);
        $this->assertStringContainsString('microphone=()', $header);
        $this->assertStringContainsString('geolocation=()', $header);
    }

    /**
     * Cross-origin isolation is the standard hardening pair for a PWA shell: it makes
     * the document eligible for `SharedArrayBuffer` and high-resolution timers, and
     * costs nothing when nobody tries to frame or open the page from another origin.
     * Same-origin assets load without `crossorigin` attributes, so this does not break
     * the PWA's own imports.
     */
    public function test_the_html_shell_is_cross_origin_isolated(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $shell = $this->get('/__pwax__/shell');

        $shell->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $shell->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp');
        $this->get('/manifest.json')->assertHeaderMissing('Cross-Origin-Opener-Policy');
    }

    /**
     * Every value is overridable. Set any of them to `null` to drop the corresponding
     * header — useful when an upstream middleware (e.g. a CSP nonce library) is the
     * authoritative source for some of them.
     */
    public function test_security_headers_are_configurable(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.security.referrer_policy', null);
        config()->set('pwax.security.frame_options', 'DENY');
        config()->set('pwax.security.cross_origin_embedder_policy', null);

        $shell = $this->get('/__pwax__/shell');

        $shell->assertHeaderMissing('Referrer-Policy');
        $shell->assertHeader('X-Frame-Options', 'DENY');
        $shell->assertHeaderMissing('Cross-Origin-Embedder-Policy');
    }
}
