<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Http\Request;
use Mxent\Pwax\Pwax;
use Mxent\Pwax\Tests\TestCase;

class MiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/page', fn () => pwax_component('pages.home'));
            $router->get('/needs-auth', fn () => redirect('/login'));
            $router->get('/goes-offsite', fn () => redirect()->away('https://example.test/sso'));
            $router->post('/save', fn () => redirect('/page'));
            $router->get('/missing', fn () => abort(404));
            $router->get('/plain', fn () => 'plain text');
        });
    }

    /**
     * `fetch` follows redirects transparently, so an untranslated 302 arrives as HTML
     * and dies in `response.json()`. In 1.x that surfaced as "Network Error".
     */
    public function test_an_internal_redirect_becomes_a_navigable_payload(): void
    {
        $response = $this->get('/needs-auth', $this->componentHeaders());

        $response->assertStatus(302);
        $response->assertJson(['redirect' => '/login']);
    }

    public function test_a_redirect_preserves_the_query_string(): void
    {
        $this->app['router']->middleware('web')->get(
            '/with-query',
            fn () => redirect('/login?next=%2Fadmin')
        );

        $response = $this->get('/with-query', $this->componentHeaders());

        // Percent-encoding is passed through untouched. Decoding it here would turn
        // `next=%2Fadmin` into a second path separator and change what the target means.
        $this->assertSame('/login?next=%2Fadmin', $response->json('redirect'));
    }

    public function test_a_redirect_after_a_post_uses_303(): void
    {
        $response = $this->post('/save', [], $this->componentHeaders());

        $response->assertStatus(303);
        $response->assertJson(['redirect' => '/page']);
    }

    /**
     * A cross-origin target cannot be handled by the SPA router, so the client is told
     * to perform a full navigation instead.
     */
    public function test_an_external_redirect_asks_for_a_full_reload(): void
    {
        $response = $this->get('/goes-offsite', $this->componentHeaders());

        $response->assertStatus(409);
        $response->assertHeader(Pwax::LOCATION_HEADER, 'https://example.test/sso');
        $response->assertJson(['location' => 'https://example.test/sso']);
    }

    public function test_redirects_are_untouched_for_ordinary_browser_requests(): void
    {
        $this->get('/needs-auth')->assertRedirect('/login');
    }

    public function test_vary_is_added_even_to_framework_responses(): void
    {
        $this->get('/missing')->assertHeader('Vary', Pwax::VARY);
        $this->get('/plain')->assertHeader('Vary', Pwax::VARY);
    }

    public function test_an_existing_vary_header_is_extended_not_replaced(): void
    {
        $this->app['router']->middleware('web')->get('/varied', function () {
            return response('x')->header('Vary', 'Accept-Encoding');
        });

        $vary = (string) $this->get('/varied')->headers->get('Vary');

        $this->assertStringContainsString('Accept-Encoding', $vary);
        $this->assertStringContainsString('X-Pwax-Component', $vary);
    }

    public function test_vary_values_are_not_duplicated(): void
    {
        $vary = (string) $this->get('/plain')->headers->get('Vary');
        $parts = array_map('trim', explode(',', $vary));

        $this->assertSame($parts, array_values(array_unique($parts)));
    }

    /**
     * Components are Blade views that may call auth(); in 1.x the component routes had
     * no middleware at all, so the session was never started.
     */
    public function test_component_routes_run_the_configured_middleware(): void
    {
        RecordingMiddleware::$ran = false;

        $this->app->make(HttpKernelContract::class)
            ->appendMiddlewareToGroup('web', RecordingMiddleware::class);

        $this->get('/__pwax__/c/' . $this->id('pages.home') . '.json')->assertOk();

        $this->assertTrue(
            RecordingMiddleware::$ran,
            'Component routes did not run the configured middleware group, so components '
            . 'would render with no session and auth() would always be a guest.'
        );
    }
}

/**
 * Records whether it ran. A class rather than a closure: middleware groups are resolved
 * by name, so a closure pushed into a group is not invoked.
 */
class RecordingMiddleware
{
    public static bool $ran = false;

    public function handle(Request $request, \Closure $next)
    {
        self::$ran = true;

        return $next($request);
    }
}
