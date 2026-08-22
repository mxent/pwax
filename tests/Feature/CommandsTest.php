<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mxent\Pwax\Facades\Pwax;
use Mxent\Pwax\Tests\TestCase;

class CommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('views/scratch'));
        File::deleteDirectory(base_path('.ai'));
        File::deleteDirectory(base_path('scratch-skill'));

        parent::tearDown();
    }

    public function test_the_clear_command_runs(): void
    {
        $this->artisan('pwax:clear')->assertSuccessful();
    }

    public function test_the_component_command_scaffolds_a_view(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.widget'])->assertSuccessful();

        $path = resource_path('views/scratch/widget.blade.php');
        $this->assertFileExists($path);

        $contents = File::get($path);
        $this->assertStringContainsString('<template>', $contents);
        $this->assertStringContainsString('<script>', $contents);
        $this->assertStringContainsString('<style scoped>', $contents);

        // Blade's own interpolation must be escaped so Vue receives it intact.
        $this->assertStringContainsString('@{{ title }}', $contents);
    }

    public function test_the_scaffolded_component_compiles(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.widget'])->assertSuccessful();

        $component = Pwax::compile('scratch.widget');

        $this->assertStringContainsString('{{ title }}', $component->template);
        $this->assertStringContainsString('export default', $component->script);
        $this->assertNotNull($component->scopeId);
    }

    public function test_the_component_command_can_omit_styles(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.plain', '--plain' => true])->assertSuccessful();

        $this->assertStringNotContainsString('<style', File::get(resource_path('views/scratch/plain.blade.php')));
    }

    public function test_the_component_command_refuses_to_overwrite(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.widget'])->assertSuccessful();
        $this->artisan('pwax:component', ['name' => 'scratch.widget'])->assertFailed();
        $this->artisan('pwax:component', ['name' => 'scratch.widget', '--force' => true])->assertSuccessful();
    }

    public function test_the_component_command_rejects_unsafe_names(): void
    {
        $this->artisan('pwax:component', ['name' => '../../../etc/passwd'])->assertFailed();
    }

    public function test_the_doctor_command_reports_problems(): void
    {
        // No icons configured, so the app is not installable.
        $this->artisan('pwax:doctor')->assertFailed();
    }

    public function test_the_doctor_command_passes_a_complete_configuration(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.source', 'cdn');

        $this->artisan('pwax:doctor')->assertSuccessful();
    }

    public function test_the_doctor_command_flags_the_import_directive(): void
    {
        config()->set('pwax.components.directive', 'import');

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('@import')
            ->assertFailed();
    }

    public function test_the_doctor_command_flags_missing_middleware(): void
    {
        config()->set('pwax.middleware', []);

        $this->artisan('pwax:doctor')->assertFailed();
    }

    public function test_the_doctor_command_flags_an_app_that_cannot_start_offline(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.assets', false);
        config()->set('pwax.service_worker.shell.enabled', false);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('offline')
            ->assertFailed();
    }

    /**
     * Cross-origin isolation is on by default, and a cross-origin asset loaded without
     * `crossorigin` is the kind of misconfiguration the browser turns into a silent
     * missing asset. The doctor is the place that catches it before deploy.
     */
    public function test_the_doctor_command_flags_cross_origin_assets_without_crossorigin(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.source', 'cdn');
        config()->set('pwax.scripts', ['https://cdn.example.test/analytics.js']);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('crossorigin')
            ->assertSuccessful();
    }

    /**
     * Same-origin assets load without `crossorigin`, so they are not flagged.
     */
    public function test_the_doctor_command_does_not_flag_same_origin_assets(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.source', 'cdn');
        config()->set('pwax.scripts', ['/js/local.js']);

        $this->artisan('pwax:doctor')->assertSuccessful();
    }

    public function test_the_precache_command_lists_what_is_cached(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $this->artisan('pwax:precache')
            ->expectsOutputToContain('/__pwax__/pwax.js')
            ->expectsOutputToContain('available offline')
            ->assertSuccessful();
    }

    public function test_the_precache_command_can_emit_the_manifest(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $this->artisan('pwax:precache', ['--json' => true])
            ->expectsOutputToContain('"hashTable"')
            ->assertSuccessful();
    }

    public function test_the_precache_command_reports_excluded_components(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.components', ['components.*']);

        $this->artisan('pwax:precache')
            ->expectsOutputToContain('excluded')
            ->assertSuccessful();
    }

    public function test_the_precache_command_verifies_components_render(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        // `pages.needs-model` cannot be rendered from its view name alone, so it cannot
        // be precached — which is exactly what --verify exists to surface.
        config()->set('pwax.service_worker.components', ['pages.needs-model']);

        $this->artisan('pwax:precache', ['--verify' => true])->assertFailed();
    }

    public function test_the_precache_command_passes_when_every_component_renders(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.components', ['components.*']);

        $this->artisan('pwax:precache', ['--verify' => true])->assertSuccessful();
    }

    public function test_the_install_command_publishes_the_config(): void
    {
        $this->artisan('pwax:install', ['--no-assets' => true, '--force' => true])->assertSuccessful();

        $this->assertFileExists(config_path('pwax.php'));

        File::delete(config_path('pwax.php'));
    }

    public function test_the_component_command_scaffolds_a_plugin(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.toast', '--plugin' => true])->assertSuccessful();

        $contents = File::get(resource_path('views/scratch/toast.blade.php'));

        $this->assertStringContainsString('install(app', $contents);
        $this->assertStringNotContainsString('<template>', $contents);
    }

    public function test_the_component_command_scaffolds_a_directive(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.focus', '--directive' => true])->assertSuccessful();

        $contents = File::get(resource_path('views/scratch/focus.blade.php'));

        $this->assertStringContainsString('name:', $contents);
        $this->assertStringContainsString('bind(el)', $contents);
    }

    public function test_the_component_command_scaffolds_a_middleware(): void
    {
        $this->artisan('pwax:component', ['name' => 'scratch.guard', '--middleware' => true])->assertSuccessful();

        $contents = File::get(resource_path('views/scratch/guard.blade.php'));

        $this->assertStringContainsString('before(next)', $contents);
    }

    public function test_the_component_command_rejects_multiple_scaffold_flags(): void
    {
        $this->artisan('pwax:component', [
            'name' => 'scratch.x',
            '--plugin' => true,
            '--directive' => true,
        ])->assertFailed();
    }

    /**
     * A misconfigured `service_worker.extend` entry fails silently at build time. The
     * doctor is the only place that surfaces it before the runtime calls a handler
     * that never got registered.
     */
    public function test_the_doctor_command_flags_an_unresolvable_extend_entry(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.source', 'cdn');
        config()->set('pwax.service_worker.extend', ['sw.does-not-exist']);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('service_worker.extend')
            ->assertFailed();
    }

    public function test_the_doctor_command_reports_well_formed_vapid_keys(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.source', 'cdn');

        // 32 bytes of zeroes, base64url-encoded — not a valid public key, but a
        // useful shape for the negative test.
        $badPublic = rtrim(strtr(base64_encode(str_repeat("\0", 32)), '+/', '-_'), '=');

        config()->set('pwax.push.public_key', $badPublic);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('public_key')
            ->assertFailed();
    }

    public function test_the_doctor_command_silently_consents_to_empty_push_config(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.source', 'cdn');

        $this->artisan('pwax:doctor')->assertSuccessful();
    }

    public function test_the_doctor_command_warns_when_a_public_key_has_no_endpoint(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.source', 'cdn');
        $this->withPushSubscriptionsTable();

        // 65 bytes starting with 0x04 is the shape the Push API expects.
        $goodPublic = rtrim(strtr(base64_encode("\x04" . str_repeat("\0", 64)), '+/', '-_'), '=');

        config()->set('pwax.push.public_key', $goodPublic);
        // pwax.push.endpoint deliberately left null.

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('pwax.push.endpoint')
            ->assertSuccessful();
    }

    /**
     * The private key is the application's — Pwax does not use it — but `pwax:doctor`
     * validates its shape, because a malformed private key is a 401 from the push service
     * that is indistinguishable from "the service is having a bad day". Now that the
     * config key exists, the check actually has something to read.
     */
    public function test_the_doctor_command_flags_a_malformed_private_key(): void
    {
        $this->manifest_clean();
        $this->withPushSubscriptionsTable();

        // A valid public key so the check enters the push branch at all.
        $goodPublic = rtrim(strtr(base64_encode("\x04" . str_repeat("\0", 64)), '+/', '-_'), '=');
        config()->set('pwax.push.public_key', $goodPublic);
        config()->set('pwax.push.endpoint', '/push/subscribe');

        // 31 bytes — one short of the 32 the Push API requires.
        $badPrivate = rtrim(strtr(base64_encode(str_repeat("\0", 31)), '+/', '-_'), '=');
        config()->set('pwax.push.private_key', $badPrivate);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('private_key')
            ->assertFailed();
    }

    /**
     * A well-formed private key passes the doctor. Both keys set, an endpoint set, and
     * the table present — the complete, valid push configuration.
     */
    public function test_the_doctor_command_accepts_a_well_formed_private_key(): void
    {
        $this->manifest_clean();
        $this->withPushSubscriptionsTable();

        $goodPublic = rtrim(strtr(base64_encode("\x04" . str_repeat("\0", 64)), '+/', '-_'), '=');
        $goodPrivate = rtrim(strtr(base64_encode(str_repeat("\0", 32)), '+/', '-_'), '=');

        config()->set('pwax.push.public_key', $goodPublic);
        config()->set('pwax.push.private_key', $goodPrivate);
        config()->set('pwax.push.endpoint', '/push/subscribe');

        $this->artisan('pwax:doctor')->assertSuccessful();
    }

    public function test_the_precache_command_verify_covers_pages(): void
    {
        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.components', ['pages.home']);
        config()->set('pwax.service_worker.pages.urls', ['/about']);

        $this->artisan('pwax:precache', ['--verify' => true])
            ->expectsOutputToContain('Probing')
            ->assertSuccessful();
    }

    public function test_the_push_endpoint_command_scaffolds_a_controller(): void
    {
        $this->artisan('pwax:push-endpoint')->assertSuccessful();

        $path = app_path('Http/Controllers/PushSubscriptionController.php');
        $this->assertFileExists($path);

        $contents = File::get($path);

        $this->assertStringContainsString('subscribe', $contents);
        $this->assertStringContainsString('unsubscribe', $contents);
        $this->assertStringContainsString('PushSubscriptionController', $contents);

        File::delete($path);
    }

    public function test_the_push_endpoint_command_refuses_to_overwrite(): void
    {
        $path = app_path('Http/Controllers/PushSubscriptionController.php');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '<?php // existing');

        try {
            $this->artisan('pwax:push-endpoint')->assertFailed();
            $this->assertSame('<?php // existing', File::get($path));

            $this->artisan('pwax:push-endpoint', ['--force' => true])->assertSuccessful();
        } finally {
            File::delete($path);
        }
    }

    public function test_the_routes_command_lists_pwax_routes(): void
    {
        $this->artisan('pwax:routes')
            ->expectsOutputToContain('pwax.')
            ->assertSuccessful();
    }

    public function test_the_routes_command_can_include_application_routes(): void
    {
        $this->artisan('pwax:routes', ['--all' => true])
            ->expectsOutputToContain('pwax.test.home')
            ->assertSuccessful();
    }

    /**
     * The manifest `id` value is a URL fragment identifier per spec — a `#` in it is a
     * bug, not a literal anchor.
     */
    public function test_the_doctor_command_flags_a_manifest_id_with_a_fragment(): void
    {
        $this->manifest_clean();

        config()->set('pwax.manifest.id', '/app#fragment');

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('fragment')
            ->assertFailed();
    }

    public function test_the_doctor_command_accepts_a_clean_manifest_id(): void
    {
        $this->manifest_clean();

        config()->set('pwax.manifest.id', '/stable-id');

        $this->artisan('pwax:doctor')->assertSuccessful();
    }

    /**
     * `display` outside the installable set is silent otherwise — the install prompt
     * simply never appears.
     */
    public function test_the_doctor_command_warns_when_display_is_not_installable(): void
    {
        $this->manifest_clean();

        config()->set('pwax.manifest.display', 'browser');

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('standalone')
            ->assertSuccessful();
    }

    public function test_the_doctor_command_warns_on_a_bad_scope(): void
    {
        $this->manifest_clean();

        config()->set('pwax.service_worker.enabled', true);
        config()->set('pwax.service_worker.scope', '/admin#frag');

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('fragment')
            ->assertFailed();
    }

    public function test_the_doctor_command_warns_when_source_maps_are_set_in_production(): void
    {
        $this->manifest_clean();

        $this->app->detectEnvironment(fn () => 'production');
        config()->set('pwax.service_worker.source_maps', true);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('source map')
            ->assertSuccessful();
    }

    public function test_the_doctor_command_warns_when_subscriptions_table_is_missing(): void
    {
        $this->manifest_clean();

        $publicKey = rtrim(strtr(base64_encode("\x04" . str_repeat("\0", 64)), '+/', '-_'), '=');
        config()->set('pwax.push.public_key', $publicKey);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('push_subscriptions')
            ->assertFailed();
    }

    /**
     * The shared `pwax:doctor` config baseline every `check*` method needs.
     *
     * Every new check has a positive path that requires a populated, valid manifest. The
     * baseline below is the smallest set that satisfies every check we have — kept here
     * and applied by every other test in this file rather than scattered through the
     * suite, so a doctor change that adds a new check only has to update one place.
     */
    public function test_the_doctor_command_warns_when_no_sharing_image_is_configured(): void
    {
        $this->manifest_clean();

        // Not an error — the app works. It is just that every link to it unfurls as a bare
        // title on every platform that reads Open Graph, and nobody on the team ever sees
        // that because nobody shares links to their own staging app.
        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('head.image')
            ->assertSuccessful();
    }

    public function test_the_doctor_command_warns_when_every_page_is_noindex(): void
    {
        $this->manifest_clean();

        config()->set('pwax.head.image', 'https://example.test/og.png');
        config()->set('pwax.head.robots', 'noindex, nofollow');

        // Correct for staging, and the reason a launched site has no traffic if it survives
        // the deploy. There is no symptom to notice: the pages render exactly as before.
        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('head.robots')
            ->assertSuccessful();
    }

    public function test_the_doctor_command_warns_about_structured_data_with_no_context(): void
    {
        $this->manifest_clean();

        config()->set('pwax.head.image', 'https://example.test/og.png');
        config()->set('pwax.head.json_ld', ['@type' => 'Organization', 'name' => 'Acme']);

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('@context')
            ->assertSuccessful();
    }

    public function test_the_doctor_command_is_quiet_about_a_head_that_is_set_up(): void
    {
        $this->manifest_clean();

        config()->set('pwax.head.image', 'https://example.test/og.png');

        $this->artisan('pwax:doctor')
            ->expectsOutputToContain('Document head is configured')
            ->assertSuccessful();
    }

    private function manifest_clean(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/i-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/i-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ]);
        config()->set('pwax.assets.source', 'cdn');
    }

    /**
     * Create the `push_subscriptions` table that the push doctor checks look for.
     */
    private function withPushSubscriptionsTable(): void
    {
        if (Schema::hasTable('push_subscriptions')) {
            return;
        }

        Schema::create('push_subscriptions', function ($t) {
            $t->id();
            $t->string('endpoint');
            $t->string('p256dh', 256);
            $t->string('auth', 64);
            $t->timestamps();
        });
    }

    public function test_the_skill_command_publishes_the_bundled_template(): void
    {
        $this->artisan('pwax:skill', ['--path' => 'scratch-skill'])
            ->expectsOutputToContain('Published the Pwax skill')
            ->assertSuccessful();

        $path = base_path('scratch-skill/SKILL.md');

        $this->assertFileExists($path);
        $this->assertStringContainsString('name: pwax', File::get($path));
    }

    public function test_the_skill_command_refuses_to_overwrite_an_existing_file(): void
    {
        $this->artisan('pwax:skill', ['--path' => 'scratch-skill'])->assertSuccessful();

        $this->artisan('pwax:skill', ['--path' => 'scratch-skill'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    }

    public function test_the_skill_command_overwrites_with_force(): void
    {
        $this->artisan('pwax:skill', ['--path' => 'scratch-skill'])->assertSuccessful();

        $this->artisan('pwax:skill', ['--path' => 'scratch-skill', '--force' => true])
            ->expectsOutputToContain('Published the Pwax skill')
            ->assertSuccessful();
    }

    public function test_the_install_command_publishes_the_skill_with_ai(): void
    {
        $this->artisan('pwax:install', ['--no-assets' => true, '--ai' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertFileExists(base_path('.ai/skills/pwax/SKILL.md'));
    }
}
