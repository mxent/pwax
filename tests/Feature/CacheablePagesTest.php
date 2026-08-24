<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

/**
 * Page payloads are `no-store` by default and the service worker honours that, so a
 * signed-in user's rendered page never reaches disk. `cacheable()` is the explicit opt-out
 * for pages that render the same for everyone, and it is what makes those routes work
 * offline rather than merely start offline.
 */
class CacheablePagesTest extends TestCase
{
    public function test_page_payloads_are_not_stored_by_default(): void
    {
        $response = $this->get('/private-page', $this->componentHeaders());

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_cacheable_page_payload_may_be_stored(): void
    {
        $response = $this->get('/public-page', $this->componentHeaders());

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringNotContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=600', $cacheControl);
        // Private by default: "the same for every visitor" is a claim about rendering,
        // not permission for a CDN to hand it to someone else.
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_a_page_can_opt_into_shared_caching(): void
    {
        $this->assertStringContainsString(
            'public',
            (string) $this->get('/shared-page', $this->componentHeaders())->headers->get('Cache-Control')
        );
    }

    public function test_the_html_shell_is_never_cacheable(): void
    {
        // The shell carries the CSRF token. Whatever the payload says, this document is
        // request-specific.
        $this->assertStringContainsString(
            'no-store',
            (string) $this->get('/public-page')->headers->get('Cache-Control')
        );
    }

    public function test_cacheable_payloads_still_vary(): void
    {
        // Without Vary, a cache can serve the JSON payload to a browser navigation.
        $this->assertStringContainsString(
            'X-Pwax-Component',
            (string) $this->get('/public-page', $this->componentHeaders())->headers->get('Vary')
        );
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/private-page', fn () => pwaxRender('pages.home'));
            $router->get('/public-page', fn () => pwaxRender('pages.home')->cacheable(600));
            $router->get('/shared-page', fn () => pwaxRender('pages.home')->cacheable(600, shared: true));
        });
    }
}
