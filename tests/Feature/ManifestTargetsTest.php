<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

/**
 * Manifest members that hand the application to the operating system.
 *
 * `share_target`, `file_handlers`, `protocol_handlers` and `shortcuts` are declarations
 * that something outside the browser may now open this app at a particular address. They
 * pass straight through to the manifest, so declaring one is a promise the package cannot
 * keep on the application's behalf: the route has to exist, accept the right method, and
 * lie inside `scope`.
 *
 * Get any of that wrong and there is no error anywhere. The user shares a photo to an
 * installed app and gets a 404, hours after the deploy that caused it. These make the same
 * mistakes fail at `pwax:doctor` instead.
 */
class ManifestTargetsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->post('/share', fn () => 'shared')->name('pwax.test.share');
        $router->get('/open', fn () => 'opened')->name('pwax.test.open');
    }

    /**
     * Everything the doctor checks besides the member under test, satisfied.
     */
    private function healthy(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);

        config()->set('pwax.assets.source', 'cdn');
    }

    // -----------------------------------------------------------------------------
    // share_target
    // -----------------------------------------------------------------------------

    public function test_a_share_target_pointing_at_a_real_route_passes(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.share_target', [
            'action' => '/share',
            'method' => 'POST',
            'enctype' => 'multipart/form-data',
            'params' => ['title' => 'title', 'text' => 'text', 'url' => 'url'],
        ]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('manifest target(s) resolve')
            ->assertSuccessful();
    }

    /**
     * The finding that started this: a manifest advertising a capability with nothing
     * behind it. The operating system offers the app in the share sheet, the user picks it,
     * and the app 404s.
     */
    public function test_a_share_target_with_no_route_is_a_problem(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.share_target', ['action' => '/nowhere', 'method' => 'POST']);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('matches no route')
            ->assertFailed();
    }

    /**
     * Worse than no route, because it looks right: the URL resolves in a browser and fails
     * only when the share actually arrives, as a 405 nobody is watching for.
     */
    public function test_a_share_target_whose_route_refuses_the_method_is_a_problem(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.share_target', ['action' => '/open', 'method' => 'POST']);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('does not accept POST')
            ->assertFailed();
    }

    public function test_a_share_target_accepting_files_must_post(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.share_target', [
            'action' => '/open',
            'method' => 'GET',
            'params' => ['files' => [['name' => 'f', 'accept' => ['image/*']]]],
        ]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('cannot be delivered in a query string')
            ->assertFailed();
    }

    public function test_a_share_target_accepting_files_needs_the_multipart_enctype(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.share_target', [
            'action' => '/share',
            'method' => 'POST',
            'params' => ['files' => [['name' => 'f', 'accept' => ['image/*']]]],
        ]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('multipart/form-data')
            ->assertFailed();
    }

    /**
     * A share arrives from outside the application with no session and no token, and the
     * worker deliberately leaves non-GET requests alone. Both are worth saying once.
     */
    public function test_a_posting_share_target_is_flagged_for_csrf_and_offline(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.share_target', [
            'action' => '/share',
            'method' => 'POST',
            'enctype' => 'multipart/form-data',
        ]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('CSRF')
            ->assertSuccessful();
    }

    // -----------------------------------------------------------------------------
    // file_handlers and protocol_handlers
    // -----------------------------------------------------------------------------

    public function test_a_file_handler_needs_an_accept_map(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.file_handlers', [['action' => '/open']]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('no "accept" map')
            ->assertFailed();
    }

    public function test_a_complete_file_handler_passes(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.file_handlers', [
            ['action' => '/open', 'accept' => ['text/csv' => ['.csv']]],
        ]);

        $this->artisan('pwax:doctor')->assertSuccessful();
    }

    public function test_a_custom_protocol_must_be_prefixed(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.protocol_handlers', [
            ['protocol' => 'invoice', 'url' => '/open?u=%s'],
        ]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('must begin with "web+"')
            ->assertFailed();
    }

    public function test_a_safelisted_protocol_needs_no_prefix(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.protocol_handlers', [
            ['protocol' => 'mailto', 'url' => '/open?u=%s'],
        ]);

        $this->artisan('pwax:doctor')->assertSuccessful();
    }

    /**
     * Without the placeholder the handler fires and throws away the link that fired it.
     */
    public function test_a_protocol_handler_needs_the_placeholder(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.protocol_handlers', [
            ['protocol' => 'web+invoice', 'url' => '/open'],
        ]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('no %s in its url')
            ->assertFailed();
    }

    // -----------------------------------------------------------------------------
    // scope
    // -----------------------------------------------------------------------------

    /**
     * A target outside scope is not an error the browser reports — it simply never fires,
     * which is the hardest kind of wrong to notice.
     */
    public function test_a_target_outside_scope_is_a_problem(): void
    {
        $this->healthy();
        config()->set('pwax.manifest.scope', '/app/');
        config()->set('pwax.manifest.start_url', '/app/');
        config()->set('pwax.manifest.shortcuts', [['name' => 'Open', 'url' => '/open']]);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('outside pwax.manifest.scope')
            ->assertFailed();
    }

    public function test_nothing_is_said_when_no_targets_are_declared(): void
    {
        $this->healthy();

        $this->artisan('pwax:doctor')
            ->doesntExpectOutputToContain('manifest target(s) resolve')
            ->assertSuccessful();
    }

    // -----------------------------------------------------------------------------
    // The manifest response
    // -----------------------------------------------------------------------------

    /**
     * `lang`, `name` and `description` follow the application locale, and this is served
     * `public, max-age=86400`. The route sits outside the `web` group today, so nothing
     * sets a locale before it renders — but that is a property of `routes.static_middleware`,
     * which is a config key, and a shared cache with no `Vary` would hand one visitor's
     * language to the next.
     */
    public function test_the_manifest_varies_on_the_language(): void
    {
        $response = $this->get('/manifest.json');

        $response->assertOk();
        $response->assertHeader('Vary', 'Accept-Language');
        $response->assertHeader('Cache-Control', 'max-age=86400, public');
    }
}
