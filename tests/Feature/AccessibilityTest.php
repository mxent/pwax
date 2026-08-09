<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

/**
 * The shell's assistive-technology semantics.
 *
 * A single-page application has to put back by hand two things a server-rendered one gets
 * for nothing: a signal that the page changed, and a document that is not permanently
 * described as loading. Both live in the shell markup, which is why they are asserted
 * here rather than only in the runtime's own tests.
 */
class AccessibilityTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/page', fn () => pwaxRender('pages.home'));
        });
    }

    public function test_the_mount_element_is_not_a_live_region(): void
    {
        $html = (string) $this->get('/page')->getContent();

        $mount = $this->elementWithId($html, 'pwax');

        // These belong to the spinner, not to the application. On the mount element they
        // outlive it: the runtime removes the preloader class and the attributes stay,
        // so every reactive text change in the app is announced for the rest of the
        // session and the whole document is labelled "Loading".
        $this->assertStringNotContainsString('role=', $mount);
        $this->assertStringNotContainsString('aria-live=', $mount);
        $this->assertStringNotContainsString('aria-label=', $mount);
    }

    public function test_the_loading_state_is_still_announced(): void
    {
        // Removing it from the mount element must not mean removing it. A visitor who
        // cannot see the spinner still needs to know the application is starting.
        $this->assertStringContainsString(
            '<span class="pwax-sr-only" role="status">Loading</span>',
            (string) $this->get('/page')->getContent()
        );
    }

    public function test_the_shell_provides_an_announcer_for_navigations(): void
    {
        $html = (string) $this->get('/page')->getContent();

        $this->assertStringContainsString('id="pwax-announcer"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
    }

    public function test_screen_reader_only_text_is_hidden_visually(): void
    {
        // `display: none` would hide it from a screen reader too, which is the mistake
        // this class exists to avoid.
        $html = (string) $this->get('/page')->getContent();

        $this->assertStringContainsString('.pwax-sr-only', $html);
        $this->assertStringContainsString('clip-path: inset(50%)', $html);
    }

    public function test_the_document_declares_its_language(): void
    {
        $this->assertStringContainsString('<html lang="en">', (string) $this->get('/page')->getContent());
    }

    public function test_a_configured_colour_that_is_not_a_colour_is_refused(): void
    {
        // Inside a <style> block Blade's escaping does not help — it escapes for HTML,
        // and CSS has its own way out. Config is not user input, so this is hardening
        // rather than a hole, but a typo should cost one wrong colour, not the stylesheet.
        config()->set('pwax.customization.init_background', '#fff; } html { display: none } .x {');

        $html = (string) $this->get('/page')->getContent();

        // The injected *rule*, not the phrase: the stylesheet's own comments discuss
        // hiding things, and a test that matched those would be asserting against prose.
        $this->assertStringNotContainsString('html { display: none }', $html);
        $this->assertStringContainsString('background: #ffffff', $html);
    }

    public function test_a_real_colour_is_kept(): void
    {
        config()->set('pwax.customization.init_spinner_color', 'oklch(70% 0.1 250)');

        $this->assertStringContainsString(
            'border-top-color: oklch(70% 0.1 250)',
            (string) $this->get('/page')->getContent()
        );
    }

    /**
     * The opening tag of the element with this id, attributes and all.
     */
    private function elementWithId(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('/<div[^>]*id="' . preg_quote($id, '/') . '"[^>]*>/', $html, $m));

        return $m[0];
    }
}
