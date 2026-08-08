<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

/**
 * The document head.
 *
 * Ordered deliberately, the way Angular's index.html is, so that reading the head of any
 * Pwax application tells the same story. The order is asserted rather than assumed because
 * two of the positions matter for more than tidiness: `charset` has to be settled before
 * anything else is parsed, and `<base>` changes how every relative URL below it resolves.
 */
class HeadTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/page', fn () => pwaxRender('pages.home'));
            $router->get('/titled', fn () => pwaxRender('pages.home')->title('About us'));
        });
    }

    public function test_the_head_is_emitted_in_the_documented_order(): void
    {
        config()->set('pwax.manifest.name', 'Acme');
        config()->set('pwax.manifest.description', 'Widgets for everyone');
        config()->set('pwax.manifest.theme_color', '#0c83ff');

        $html = (string) $this->get('/page')->getContent();

        $order = ['<meta charset', '<title>', 'name="viewport"', 'name="description"', 'rel="manifest"'];
        $last = -1;

        foreach ($order as $needle) {
            $at = strpos($html, $needle);

            $this->assertIsInt($at, sprintf('%s is missing from the head.', $needle));
            $this->assertGreaterThan($last, $at, sprintf('%s is out of order.', $needle));

            $last = $at;
        }
    }

    public function test_the_title_falls_back_to_the_application_name(): void
    {
        config()->set('pwax.manifest.name', 'Acme');

        $this->get('/page')->assertSee('<title>Acme</title>', false);
    }

    public function test_a_page_can_set_its_own_title(): void
    {
        $this->get('/titled')->assertSee('<title>About us</title>', false);
    }

    public function test_the_title_template_wraps_a_page_title(): void
    {
        config()->set('pwax.head.title_template', ':title · Acme');

        $this->get('/titled')->assertSee('<title>About us · Acme</title>', false);
    }

    /**
     * A page that set no title of its own must not be run through the template, or
     * ':title · Acme' against a fallback of 'Acme' renders 'Acme · Acme'.
     */
    public function test_the_template_is_not_applied_to_the_fallback(): void
    {
        config()->set('pwax.manifest.name', 'Acme');
        config()->set('pwax.head.title_template', ':title · Acme');

        $this->get('/page')->assertSee('<title>Acme</title>', false);
    }

    public function test_a_page_title_reaches_the_payload_too(): void
    {
        // Set only in the shell it would be right for the page you landed on and stale for
        // every client-side navigation after it.
        $this->get('/titled', $this->componentHeaders())->assertJsonPath('title', 'About us');
    }

    /**
     * `<base href>` rewrites every relative URL in the document, including the `src` of
     * every image in every component. Switching it on by default would quietly break
     * working applications, so it is opt-in.
     */
    public function test_no_base_tag_is_emitted_by_default(): void
    {
        $this->assertStringNotContainsString('<base ', (string) $this->get('/page')->getContent());
    }

    public function test_a_base_tag_is_emitted_when_configured(): void
    {
        config()->set('pwax.head.base', '/app/');

        $this->get('/page')->assertSee('<base href="/app/">', false);
    }

    public function test_the_icon_comes_from_the_manifest(): void
    {
        config()->set('pwax.manifest.icons', [
            ['src' => '/icons/512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => '/icons/64.png', 'sizes' => '64x64', 'type' => 'image/png'],
            ['src' => '/icons/mask.png', 'sizes' => '192x192', 'purpose' => 'maskable'],
        ]);

        // The smallest square at or above 32px: a tab favicon is drawn at 16 or 32, and
        // shipping a 512px PNG for it is wasted bytes on every page load. A maskable icon
        // is never used — it is drawn expecting the platform to crop it.
        $this->get('/page')->assertSee('<link rel="icon" href="/icons/64.png">', false);
    }

    public function test_a_dark_theme_colour_is_emitted_alongside_the_light_one(): void
    {
        config()->set('pwax.manifest.theme_color', '#ffffff');
        config()->set('pwax.head.theme_color_dark', '#111111');

        $html = (string) $this->get('/page')->getContent();

        $this->assertStringContainsString('media="(prefers-color-scheme: light)" content="#ffffff"', $html);
        $this->assertStringContainsString('media="(prefers-color-scheme: dark)" content="#111111"', $html);
    }
}
