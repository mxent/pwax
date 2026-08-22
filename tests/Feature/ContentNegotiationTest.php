<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwax;
use Mxent\Pwax\Tests\TestCase;

class ContentNegotiationTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/home', fn () => pwaxRender('pages.home'))->name('pwax.test.page');
            $router->get('/greet', fn () => pwaxRender('pages.with-data', ['name' => 'Ada']));
        });
    }

    public function test_a_browser_navigation_receives_the_shell(): void
    {
        $response = $this->get('/home');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertStringContainsString('<div id="pwax"', $response->getContent());
        $this->assertStringContainsString('id="pwax-config"', $response->getContent());
    }

    /**
     * The change that removes the first-paint round trip: the shell already contains the
     * component for the URL being loaded.
     */
    public function test_the_shell_embeds_the_component_for_this_url(): void
    {
        $this->assertStringContainsString('id="pwax-initial"', $this->get('/home')->getContent());
    }

    /**
     * The whole component is inline, so first paint makes no follow-up request at all.
     */
    public function test_the_embedded_payload_is_complete(): void
    {
        $payload = $this->initialPayload($this->get('/home')->getContent());

        $this->assertSame('/home', $payload['url']);
        $this->assertNotEmpty($payload['component']['template']);
        $this->assertNotEmpty($payload['component']['script']);
        $this->assertNotEmpty($payload['component']['hash']);
    }

    /**
     * A page is rendered with controller data, so it cannot be re-derived from its view
     * name alone: fetching `/__pwax__/c/{id}.js` for it would render the view with no
     * data and leave every `@json($var)` in its script undefined. Page payloads must
     * therefore carry the script itself, never a URL to fetch it from.
     */
    public function test_a_page_payload_advertises_no_module_url(): void
    {
        $this->assertNull($this->initialPayload($this->get('/greet')->getContent())['component']['module']);
        $this->assertNull($this->get('/greet', $this->componentHeaders())->json('module'));
    }

    public function test_a_data_bound_script_reaches_the_client_rendered(): void
    {
        $payload = $this->initialPayload($this->get('/greet')->getContent());

        $this->assertStringContainsString('"Ada"', $payload['component']['script']);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialPayload(string $html): array
    {
        preg_match('/id="pwax-initial"[^>]*>(.*?)<\/script>/s', $html, $matches);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_a_runtime_request_receives_json(): void
    {
        $response = $this->get('/home', $this->componentHeaders());

        $response->assertOk();
        $response->assertJsonStructure(['id', 'hash', 'template', 'script', 'module']);
    }

    /**
     * Without Vary, a shared cache stores one representation and serves it for the
     * other — raw JSON to a browser, or the HTML shell to the runtime.
     */
    public function test_both_representations_declare_vary(): void
    {
        $this->get('/home')->assertHeader('Vary', Pwax::VARY);
        $this->get('/home', $this->componentHeaders())->assertHeader('Vary', Pwax::VARY);
    }

    public function test_page_responses_are_never_stored_by_a_shared_cache(): void
    {
        foreach ([[], $this->componentHeaders()] as $headers) {
            $cacheControl = (string) $this->get('/home', $headers)->headers->get('Cache-Control');

            $this->assertStringContainsString('no-store', $cacheControl);
            $this->assertStringContainsString('private', $cacheControl);
        }
    }

    public function test_controller_data_reaches_the_component(): void
    {
        $response = $this->get('/greet', $this->componentHeaders());

        $this->assertStringContainsString('Hello Ada', $response->json('template'));
    }

    public function test_the_shell_view_is_configurable(): void
    {
        config()->set('pwax.shell', 'pwax::layouts.shell');

        $this->get('/home')->assertOk();
    }

    public function test_the_shell_declares_a_csrf_token(): void
    {
        $this->assertStringContainsString('name="csrf-token"', $this->get('/home')->getContent());
    }

    public function test_the_shell_applies_a_csp_nonce_when_configured(): void
    {
        config()->set('pwax.csp.nonce', 'n0nce-value');

        $content = $this->get('/home')->getContent();

        $this->assertStringContainsString('nonce="n0nce-value"', $content);
    }

    public function test_the_nonce_may_be_a_callable(): void
    {
        config()->set('pwax.csp.nonce', fn (): string => 'from-callable');

        $this->assertStringContainsString('nonce="from-callable"', $this->get('/home')->getContent());
    }

    public function test_a_callable_nonce_is_resolved_once_for_the_whole_document(): void
    {
        // The head partial, the foot partial, the shell's own <noscript> block and the
        // runtime config all ask for the nonce. A `Content-Security-Policy` header names
        // exactly one, so a callable minting a fresh value per call would have every block
        // but one refused — and the failure is a page that renders unstyled with a console
        // full of violations, which reads as a broken application rather than a config bug.
        $calls = 0;

        config()->set('pwax.csp.nonce', function () use (&$calls): string {
            $calls++;

            return 'nonce-' . $calls;
        });

        $content = (string) $this->get('/home')->getContent();

        preg_match_all('/nonce="([^"]+)"/', $content, $matches);

        $this->assertNotSame([], $matches[1], 'the document carried no nonce at all');
        $this->assertSame([$matches[1][0]], array_values(array_unique($matches[1])));
    }

    public function test_the_noscript_block_that_hides_the_preloader_carries_the_nonce(): void
    {
        // This rule lifts an opaque, full-viewport cover off the "enable JavaScript"
        // message underneath it. Refused, the only visitor who ever reaches this markup is
        // left looking at a spinner that will never stop.
        config()->set('pwax.csp.nonce', 'n0nce-value');

        $this->assertStringContainsString(
            '<style nonce="n0nce-value">.pwax-preloader{display:none}</style>',
            (string) $this->get('/home')->getContent()
        );
    }
}
