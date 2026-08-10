<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwa\AssetManifest;
use Mxent\Pwax\Pwa\ServiceWorker;
use Mxent\Pwax\Tests\TestCase;

/**
 * How `/sw.js` is assembled.
 *
 * A header comment, a preamble carrying everything the server decided, and the prebuilt
 * worker — concatenated, not pulled in with `importScripts`, because that would cost a
 * blocking request during install and could pair a new preamble with an HTTP-cached old
 * bundle. One byte stream cannot come apart that way.
 */
class ServiceWorkerBundleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pwax.service_worker.enabled', true);
    }

    private function worker(): string
    {
        return (string) $this->get('/sw.js')->getContent();
    }

    /**
     * The preamble, decoded — so an assertion is about a value rather than about how JSON
     * happened to escape it.
     *
     * @return array<string, mixed>
     */
    private function preamble(): array
    {
        preg_match('/self\\.__PWAX_SW__ = (\\{.*?\\});\\n/s', $this->worker(), $m);

        $this->assertNotEmpty($m, 'no preamble found in the served worker');

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_it_serves_the_preamble_then_the_bundle(): void
    {
        $body = $this->worker();

        $this->assertStringContainsString('self.__PWAX_SW__ = {', $body);
        $this->assertStringContainsString('addEventListener("install"', $body);

        // Order matters: the bundle destructures the global on its first line.
        $this->assertLessThan(
            strpos($body, 'addEventListener("install"'),
            strpos($body, 'self.__PWAX_SW__')
        );
    }

    public function test_the_manifest_hash_reaches_the_worker_bytes(): void
    {
        $hash = $this->app->make(AssetManifest::class)->get()['hash'];

        // The whole update mechanism rests on this. A browser treats a worker as new only
        // if its bytes differ, so a worker whose source never changed would leave every
        // existing install on the build it first saw.
        $this->assertStringContainsString($hash, $this->worker());
    }

    public function test_a_change_anywhere_changes_the_worker(): void
    {
        $before = $this->worker();

        config()->set('pwax.service_worker.version', 'v2');
        $this->app->make(AssetManifest::class)->flush();

        $this->assertNotSame($before, $this->worker());
    }

    public function test_the_offline_document_carries_the_applications_language(): void
    {
        config()->set('app.locale', 'ar');
        config()->set('pwax.manifest.dir', 'rtl');

        $html = $this->preamble()['config']['offlineHtml'];

        // It was a JavaScript string hardcoded to `lang="en"`, inside a Blade template.
        $this->assertStringContainsString('lang="ar"', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
    }

    public function test_the_offline_document_says_what_happened(): void
    {
        $this->assertStringContainsString(
            'This page is not available offline',
            $this->preamble()['config']['offlineHtml']
        );
    }

    public function test_extend_appends_a_view_after_the_worker(): void
    {
        config()->set('pwax.service_worker.extend', ['sw.push']);

        $body = $this->worker();

        $this->assertStringContainsString("self.addEventListener('push'", $body);

        // After, so it can use everything the worker defines.
        $this->assertGreaterThan(strpos($body, 'self.__PWAX_SW__'), strpos($body, "'push'"));
    }

    public function test_extend_appends_a_file_from_disk(): void
    {
        $path = sys_get_temp_dir() . '/pwax-extend-' . getmypid() . '.js';
        file_put_contents($path, "self.addEventListener('sync', () => {});\n");

        config()->set('pwax.service_worker.extend', [$path]);

        $this->assertStringContainsString("self.addEventListener('sync'", $this->worker());

        @unlink($path);
    }

    public function test_an_extend_entry_that_resolves_to_nothing_is_reported_not_ignored(): void
    {
        config()->set('pwax.service_worker.extend', ['nope.not-a-view']);

        // A comment rather than silence, and a comment rather than a fatal: a typo here
        // must not take the worker — and the whole offline application — down with it.
        $body = $this->worker();

        $this->assertStringContainsString('neither a view nor a readable file', $body);
        $this->assertStringContainsString('addEventListener("install"', $body);
    }

    public function test_a_published_blade_worker_still_wins(): void
    {
        config()->set('pwax.service_worker.blade', 'sw.legacy');

        $body = $this->worker();

        // Someone who forked the 4.0 view to add a handler must not have it silently
        // replaced by an upgrade.
        $this->assertStringContainsString('// a fork of the old worker', $body);
        $this->assertStringNotContainsString('self.__PWAX_SW__', $body);
    }

    public function test_the_worker_source_map_is_served(): void
    {
        $response = $this->get('/__pwax__/pwax-sw.js.map');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function test_the_bundle_is_minified(): void
    {
        // The point of building it: it was 55 kB of commented source served on every
        // update check, because a Blade template cannot be minified.
        $this->assertLessThan(
            30000,
            strlen($this->app->make(ServiceWorker::class)->build(['hash' => 'x']))
        );
    }
}
