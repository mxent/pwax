<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Exceptions\ComponentNotAllowed;
use Mxent\Pwax\Exceptions\InvalidComponentId;
use Mxent\Pwax\Facades\Pwax;
use Mxent\Pwax\Http\Responses\ComponentResponse;
use Mxent\Pwax\Tests\TestCase;

class PwaxTest extends TestCase
{
    public function test_resolves_a_named_route_to_a_path(): void
    {
        $this->assertSame('/about', Pwax::route('pwax.test.about'));
    }

    public function test_returns_an_absolute_url_when_asked(): void
    {
        $this->assertSame('http://localhost/about', Pwax::route('pwax.test.about', [], true));
    }

    public function test_substitutes_route_parameters(): void
    {
        $this->assertSame('/users/7', Pwax::route('pwax.test.user', ['id' => 7]));
    }

    public function test_preserves_a_query_string(): void
    {
        $this->assertSame('/about?tab=team', Pwax::route('pwax.test.about', ['tab' => 'team']));
    }

    /**
     * 1.x swallowed unknown route names and quietly returned the home page, so a typo
     * in a link was invisible until someone clicked it.
     */
    public function test_an_unknown_route_throws_in_debug_mode(): void
    {
        config()->set('app.debug', true);

        $this->expectException(\Throwable::class);

        Pwax::route('no.such.route');
    }

    public function test_an_unknown_route_falls_back_in_production(): void
    {
        config()->set('app.debug', false);

        $this->assertSame('/', Pwax::route('no.such.route'));
    }

    public function test_mints_and_resolves_component_identifiers(): void
    {
        $this->assertSame('pages.home', Pwax::resolve(Pwax::id('pages.home')));
    }

    public function test_builds_component_urls_for_each_format(): void
    {
        $id = Pwax::id('pages.home');

        $this->assertSame('/__pwax__/c/' . $id . '.json', Pwax::url('pages.home'));
        $this->assertSame('/__pwax__/c/' . $id . '.js', Pwax::url('pages.home', 'js'));
        $this->assertSame('/__pwax__/c/' . $id . '.css', Pwax::url('pages.home', 'css'));
    }

    public function test_compiles_a_component_with_its_identifier_attached(): void
    {
        $component = Pwax::compile('pages.home');

        $this->assertSame('pages.home', $component->view);
        $this->assertSame(Pwax::id('pages.home'), $component->id);
        $this->assertNotEmpty($component->hash());
    }

    public function test_an_addressable_payload_advertises_its_module_url(): void
    {
        $payload = Pwax::payload(Pwax::compile('pages.home'));

        $this->assertStringEndsWith('.js', $payload['module']);
        $this->assertArrayHasKey('script', $payload);
    }

    /**
     * A component rendered with controller data cannot be re-fetched from its view name,
     * so it must never advertise a URL that would render it without that data.
     */
    public function test_a_non_addressable_payload_carries_the_script_instead(): void
    {
        $payload = Pwax::payload(Pwax::compile('pages.home'), addressable: false);

        $this->assertNull($payload['module']);
        $this->assertNotEmpty($payload['script']);
    }

    public function test_rejects_an_invalid_view_name(): void
    {
        $this->expectException(InvalidComponentId::class);

        Pwax::id('../../config/database');
    }

    public function test_enforces_the_allowlist(): void
    {
        config()->set('pwax.components.allowed', ['pages.*']);

        $this->assertSame('pages.home', Pwax::resolve(Pwax::id('pages.home')));

        $this->expectException(ComponentNotAllowed::class);
        Pwax::resolve(Pwax::id('components.modal'));
    }

    public function test_the_packages_own_views_bypass_the_allowlist(): void
    {
        config()->set('pwax.components.allowed', ['pages.*']);

        $this->assertSame('pwax::components.loader', Pwax::resolve(Pwax::id('pwax::components.loader')));
    }

    public function test_detects_a_runtime_request(): void
    {
        $this->assertFalse(Pwax::wantsComponent(request()));

        request()->headers->set('X-Pwax-Component', 'true');

        $this->assertTrue(Pwax::wantsComponent(request()));
    }

    public function test_the_helper_functions_are_available(): void
    {
        $this->assertSame('/about', pwax_route('pwax.test.about'));
        $this->assertInstanceOf(\Mxent\Pwax\Pwax::class, pwax());
        $this->assertInstanceOf(
            ComponentResponse::class,
            pwax_component('pages.home')
        );
    }

    /**
     * `vue()` and `router()` are generic enough to collide with application code, so
     * they are opt-in in 2.0.
     *
     * Asserted on the config default rather than with `function_exists()`: PHP function
     * definitions are process-global, so once any test in the run enables them they stay
     * defined for every later test.
     */
    public function test_the_one_x_globals_are_opt_in(): void
    {
        $this->assertFalse(config('pwax.helpers.global'));
    }
}
