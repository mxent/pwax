<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwa\WebManifest;
use Mxent\Pwax\Tests\TestCase;

class WebManifestTest extends TestCase
{
    private function manifest(): WebManifest
    {
        return $this->app->make(WebManifest::class);
    }

    public function test_it_defaults_the_identity_to_the_start_url(): void
    {
        config()->set('pwax.manifest.start_url', '/app');

        // Without `id`, a browser identifies the install by `start_url` — so changing
        // that later orphans every existing install and silently creates a second app.
        $this->assertSame('/app', $this->manifest()->toArray()['id']);
    }

    public function test_an_explicit_identity_wins(): void
    {
        config()->set('pwax.manifest.id', '/stable-identity');
        config()->set('pwax.manifest.start_url', '/app');

        $this->assertSame('/stable-identity', $this->manifest()->toArray()['id']);
    }

    public function test_it_defaults_the_language_to_the_application_locale(): void
    {
        $this->app->setLocale('pt_BR');

        $this->assertSame('pt-BR', $this->manifest()->toArray()['lang']);
    }

    public function test_it_drops_empty_members_recursively(): void
    {
        config()->set('pwax.manifest.description', null);
        config()->set('pwax.manifest.shortcuts', []);
        config()->set('pwax.manifest.icons', [
            ['src' => '/icon.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => null],
        ]);

        $manifest = $this->manifest()->toArray();

        // `"description": null` makes the whole manifest invalid; omitting the key does not.
        $this->assertArrayNotHasKey('description', $manifest);
        $this->assertArrayNotHasKey('shortcuts', $manifest);
        $this->assertArrayNotHasKey('purpose', $manifest['icons'][0]);
    }

    public function test_it_keeps_false_and_zero(): void
    {
        config()->set('pwax.manifest.prefer_related_applications', false);

        // A plain `array_filter` would delete a member whose entire purpose is to say no.
        $this->assertArrayHasKey('prefer_related_applications', $this->manifest()->toArray());
        $this->assertFalse($this->manifest()->toArray()['prefer_related_applications']);
    }

    public function test_cleaned_lists_stay_lists(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/a.png', 'sizes' => '192x192'],
            [],
            ['src' => '/b.png', 'sizes' => '512x512'],
        ]);

        // A list with a hole encodes as a JSON object, which is not a valid `icons` value.
        $this->assertStringContainsString('"icons": [', $this->manifest()->toJson());
        $this->assertCount(2, $this->manifest()->toArray()['icons']);
    }

    public function test_it_reports_the_declared_square_sizes(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/a.png', 'sizes' => '192x192'],
            ['src' => '/b.png', 'sizes' => '512x512 1024x1024'],
            ['src' => '/wide.png', 'sizes' => '640x320'],
        ]);

        $this->assertSame([192, 512, 1024], $this->manifest()->declaredSizes());
    }

    public function test_it_detects_a_maskable_icon(): void
    {
        config()->set('pwax.manifest.icons', [['src' => '/a.png', 'sizes' => '192x192']]);
        $this->assertFalse($this->manifest()->hasMaskableIcon());

        config()->set('pwax.manifest.icons', [
            ['src' => '/a.png', 'sizes' => '512x512', 'purpose' => 'maskable'],
        ]);
        $this->assertTrue($this->manifest()->hasMaskableIcon());
    }

    public function test_it_picks_the_nearest_icon_for_ios(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/icon-192.png', 'sizes' => '192x192'],
            ['src' => '/icon-512.png', 'sizes' => '512x512'],
        ]);

        // iOS reads none of the manifest for the home screen; without the tag it installs
        // the app with a screenshot of the page as its icon.
        $this->assertSame('/icon-192.png', $this->manifest()->appleTouchIcon());
    }

    public function test_it_will_not_offer_a_maskable_icon_to_ios(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/maskable.png', 'sizes' => '192x192', 'purpose' => 'maskable'],
            ['src' => '/plain.png', 'sizes' => '512x512', 'purpose' => 'any'],
        ]);

        // A maskable icon is drawn expecting the platform to crop it; iOS does not, so
        // using one there clips the artwork.
        $this->assertSame('/plain.png', $this->manifest()->appleTouchIcon());
    }

    public function test_it_has_no_apple_icon_when_none_is_configured(): void
    {
        config()->set('pwax.manifest.icons', []);

        $this->assertNull($this->manifest()->appleTouchIcon());
    }
}
