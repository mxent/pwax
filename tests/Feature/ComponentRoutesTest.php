<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwax;
use Mxent\Pwax\Support\ComponentId;
use Mxent\Pwax\Tests\TestCase;

class ComponentRoutesTest extends TestCase
{
    private function url(string $view): string
    {
        return '/__pwax__/c/' . $this->id($view) . '.js';
    }

    public function test_serves_a_component_as_an_es_module(): void
    {
        $response = $this->get($this->url('pages.home'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        $body = $response->getContent();

        // One request must yield everything the client needs, which is what removes the
        // need for blob: and data: URLs in script-src.
        $this->assertStringContainsString('const __pwaxTemplate =', $body);
        $this->assertStringContainsString('const __pwaxStyle =', $body);
        $this->assertStringContainsString('export {', $body);
        $this->assertStringContainsString('export default', $body);
    }

    public function test_the_module_body_cannot_break_out_of_a_script_tag(): void
    {
        $body = $this->get($this->url('pages.home'))->getContent();

        $this->assertStringNotContainsString('</script>', $body);
    }

    public function test_component_responses_carry_an_etag_and_vary(): void
    {
        $response = $this->get($this->url('pages.home'));

        $response->assertHeader('Vary', Pwax::VARY);
        $this->assertNotEmpty($response->headers->get('ETag'));

        // `private`, not `public`: a component can render differently per user.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_matching_if_none_match_returns_304(): void
    {
        $url = $this->url('pages.home');
        $etag = $this->get($url)->headers->get('ETag');

        $response = $this->get($url, ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $response->assertHeader('ETag', $etag);
    }

    public function test_a_weak_etag_still_matches(): void
    {
        $url = $this->url('pages.home');
        $etag = $this->get($url)->headers->get('ETag');

        $this->get($url, ['If-None-Match' => 'W/' . $etag])->assertStatus(304);
    }

    public function test_a_stale_if_none_match_returns_the_body(): void
    {
        $this->get($this->url('pages.home'), ['If-None-Match' => '"stale"'])->assertOk();
    }

    /**
     * The security property of the redesign: without a valid signature nothing resolves,
     * so `/__pwax__/c/...` cannot be used to render arbitrary application views.
     */
    public function test_an_unsigned_identifier_is_rejected(): void
    {
        $this->get('/__pwax__/c/pages_home.js')->assertStatus(400);
    }

    public function test_a_tampered_identifier_is_rejected(): void
    {
        $id = $this->id('pages.home');
        $tampered = substr($id, 0, -1) . ($id[strlen($id) - 1] === 'a' ? 'b' : 'a');

        $this->get('/__pwax__/c/' . $tampered . '.js')->assertStatus(400);
    }

    public function test_a_signature_from_another_key_is_rejected(): void
    {
        // Minted by an application with a different APP_KEY: the shape is right, so only
        // the signature check can reject it.
        $foreign = (new ComponentId('a-completely-different-key'))->encode('pages.home');

        $this->get('/__pwax__/c/' . $foreign . '.js')->assertStatus(400);
    }

    public function test_error_responses_do_not_leak_internals(): void
    {
        $response = $this->get('/__pwax__/c/deadbeefdeadbeefdeadbeef.js');

        $response->assertStatus(400);
        // Still valid JavaScript, so a failed import fails cleanly rather than with a
        // parse error, and it carries nothing about why.
        $this->assertSame('// pwax: component unavailable', $response->getContent());
    }

    /**
     * A component has exactly one representation. The separate `.css` and `.json`
     * endpoints were dropped: nothing consumed them, and both rendered the view without
     * its controller data, so their output was misleading precisely where someone would
     * have reached for it.
     */
    public function test_there_is_only_one_component_representation(): void
    {
        $id = $this->id('pages.home');

        $this->get('/__pwax__/c/' . $id . '.js')->assertOk();
        $this->get('/__pwax__/c/' . $id . '.css')->assertNotFound();
        $this->get('/__pwax__/c/' . $id . '.json')->assertNotFound();
    }

    public function test_a_missing_view_is_a_404_not_a_500(): void
    {
        $this->get('/__pwax__/c/' . $this->id('pages.does-not-exist') . '.js')->assertStatus(404);
    }

    public function test_an_allowlist_blocks_views_outside_it(): void
    {
        config()->set('pwax.components.allowed', ['pages.*']);

        $this->get($this->url('pages.home'))->assertOk();
        $this->get($this->url('components.modal'))->assertStatus(403);
    }

    public function test_the_runtime_bundle_is_served_and_cached_hard(): void
    {
        $response = $this->get('/__pwax__/pwax.js');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('pwax', $response->getContent());
    }

    public function test_the_runtime_bundle_supports_conditional_requests(): void
    {
        $etag = $this->get('/__pwax__/pwax.js')->headers->get('ETag');

        $this->get('/__pwax__/pwax.js', ['If-None-Match' => $etag])->assertStatus(304);
    }
}
