<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

/**
 * Serving `dist/pwax-json.js`.
 *
 * A second bundle rather than part of the runtime, because it carries @json-render/vue
 * and its dependencies — around 82 kB gzipped against the runtime's 9.7 kB — and only an
 * application that renders a `<PwaxJson>` ever needs it. It is served exactly like the
 * runtime: hard-cached, fingerprinted in its URL, and revalidated by ETag for anyone who
 * asks anyway.
 */
class JsonRuntimeEndpointTest extends TestCase
{
    public function test_the_renderer_is_served(): void
    {
        $response = $this->get('/__pwax__/pwax-json.js');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('PwaxJson', (string) $response->getContent());
    }

    public function test_it_is_cached_hard_because_its_url_carries_the_digest(): void
    {
        // Asserted by directive rather than as a whole string: Symfony normalises the
        // header and sorts them, so pinning the order tests the framework's spelling.
        $control = (string) $this->get('/__pwax__/pwax-json.js')->headers->get('Cache-Control');

        $this->assertStringContainsString('immutable', $control);
        $this->assertStringContainsString('max-age=31536000', $control);
        $this->assertStringContainsString('public', $control);
    }

    public function test_a_matching_etag_gets_a_304(): void
    {
        $etag = $this->get('/__pwax__/pwax-json.js')->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get('/__pwax__/pwax-json.js')
            ->assertStatus(304);
    }

    public function test_the_source_map_is_served_so_devtools_has_something_to_read(): void
    {
        $this->get('/__pwax__/pwax-json.js.map')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function test_the_bundle_ends_with_a_pointer_to_that_map(): void
    {
        $this->assertStringContainsString(
            'sourceMappingURL=pwax-json.js.map',
            (string) $this->get('/__pwax__/pwax-json.js')->getContent(),
            'Without the comment the map is served and never fetched, and every contributor '
            . 'who opens devtools steps through a 380 kB minified bundle.'
        );
    }
}
