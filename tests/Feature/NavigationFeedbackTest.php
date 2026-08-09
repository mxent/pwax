<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

/**
 * What a visitor sees while a navigation is in flight.
 *
 * A client-side navigation has no address-bar spinner, so a slow page used to give no
 * sign that anything was happening — and then the worst possible sign, replacing the page
 * being read with the word "Loading". The progress bar is the signal now, and the page
 * underneath it does not move until its replacement is ready.
 *
 * The bar's colour, height and the transition's duration live in the shell's stylesheet
 * rather than in the runtime config, because they are style. Only the two things the
 * runtime has to make decisions about — how long to wait, and whether to trickle — travel
 * in the config island.
 */
class NavigationFeedbackTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/page', fn () => pwaxRender('pages.home'));
        });
    }

    public function test_the_first_load_is_covered_by_the_spinner(): void
    {
        $html = (string) $this->get('/page')->getContent();

        // A document arriving is the browser's own wait, and the gap between it and the
        // runtime mounting is what the spinner covers.
        $this->assertStringContainsString('@keyframes pwax-spin', $html);
        $this->assertStringContainsString('border-top-color: #0c83ff', $html);
    }

    public function test_the_spinner_can_be_turned_off(): void
    {
        config()->set('pwax.customization.init_spinner', false);

        // For an application that renders its own skeleton into the mount element.
        $this->assertStringNotContainsString(
            '@keyframes pwax-spin',
            (string) $this->get('/page')->getContent()
        );
    }

    public function test_the_bar_is_not_rendered_by_the_shell(): void
    {
        // It belongs to navigations, and the runtime creates it on the first one slow
        // enough to need it — so an application whose navigations are all fast never puts
        // it in the document at all.
        $this->assertStringNotContainsString(
            'id="pwax-progress"',
            (string) $this->get('/page')->getContent()
        );
    }

    public function test_the_progress_bar_is_styled_in_the_shell(): void
    {
        $html = (string) $this->get('/page')->getContent();

        $this->assertStringContainsString('#pwax-progress', $html);
        // Fixed and scaled rather than sized, so it never takes part in layout and can
        // never reflow the page underneath it.
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('transform: scaleX(0)', $html);
    }

    public function test_the_bar_takes_the_spinner_colour_by_default(): void
    {
        config()->set('pwax.customization.init_spinner_color', '#ff0066');

        // An application that set one has already said what its accent is.
        $this->assertStringContainsString(
            'background: #ff0066',
            (string) $this->get('/page')->getContent()
        );
    }

    public function test_the_bar_can_be_coloured_and_sized(): void
    {
        config()->set('pwax.progress.color', '#00aa55');
        config()->set('pwax.progress.height', 5);

        $html = (string) $this->get('/page')->getContent();

        $this->assertStringContainsString('background: #00aa55', $html);
        $this->assertStringContainsString('height: 5px', $html);
    }

    public function test_the_runtime_is_told_how_to_pace_the_bar(): void
    {
        config()->set('pwax.progress.delay', 400);
        config()->set('pwax.progress.trickle', false);

        $config = $this->runtimeConfig();

        $this->assertSame(400, $config['progress']['delay']);
        $this->assertFalse($config['progress']['trickle']);
    }

    public function test_the_bar_can_be_switched_off_entirely(): void
    {
        config()->set('pwax.progress.enabled', false);

        // `false`, not an empty array: the runtime reads it as "build nothing" — no
        // element, no timers — rather than as settings it should apply.
        $this->assertFalse($this->runtimeConfig()['progress']);
    }

    public function test_the_page_transition_is_named_and_timed(): void
    {
        $html = (string) $this->get('/page')->getContent();

        $this->assertStringContainsString('.pwax-page-enter-active', $html);
        $this->assertStringContainsString('transition: opacity 150ms ease', $html);
        $this->assertSame('pwax-page', $this->runtimeConfig()['transition']);
    }

    public function test_a_custom_transition_can_be_named(): void
    {
        config()->set('pwax.transition.name', 'slide');
        config()->set('pwax.transition.duration', 400);

        $this->assertSame('slide', $this->runtimeConfig()['transition']);
        $this->assertStringContainsString(
            'transition: opacity 400ms ease',
            (string) $this->get('/page')->getContent()
        );
    }

    public function test_both_defer_to_a_reduced_motion_preference(): void
    {
        // Motion is a preference, and both of these are motion.
        $this->assertStringContainsString(
            'prefers-reduced-motion',
            (string) $this->get('/page')->getContent()
        );
    }

    public function test_the_loader_no_longer_carries_a_visible_message(): void
    {
        $html = (string) $this->get('/page')->getContent();

        // It is not what you see between two pages any more — the page you are on stays
        // rendered — so it speaks only to assistive technology, which has no progress bar
        // to look at.
        $this->assertStringNotContainsString('One moment please', $html);
        $this->assertStringContainsString('pwax-sr-only', $html);
    }

    /**
     * The runtime configuration island, decoded.
     *
     * @return array<string, mixed>
     */
    private function runtimeConfig(): array
    {
        $html = (string) $this->get('/page')->getContent();

        $this->assertSame(
            1,
            preg_match('/<script type="application\/json" id="pwax-config"[^>]*>(.*?)<\/script>/s', $html, $m)
        );

        // The island is emitted with the JSON_HEX_* flags, which produce `<`-style
        // escapes — valid JSON, decoded natively. Nothing to unescape first.
        /** @var array<string, mixed> $config */
        $config = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        return $config;
    }
}
