<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Support\Shell;
use Mxent\Pwax\Tests\TestCase;

/**
 * Hinting at the renderer, but only where it is going to be used.
 *
 * `<PwaxJson>` fetches the bundle on first render — after Vue has loaded, compiled the
 * page and reached that node. That is the latest possible moment for the largest asset
 * the page will fetch, and the visitor watches the loading slot while it arrives.
 *
 * The fix is the one `Pwax::importedUrls()` already uses for `@pwaxImport`: read the
 * compiled template back and hint at what it is about to need. The restraint matters as
 * much as the hint — an 82 kB preload on a page that never renders a document is a
 * straight regression for every other page in the application.
 */
class JsonPreloadTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/document', fn () => pwaxRender('pages.json'));
            $router->get('/plain', fn () => pwaxRender('pages.home'));
        });
    }

    public function test_a_page_that_renders_a_document_hints_at_the_renderer(): void
    {
        $this->assertStringContainsString($this->hint(), (string) $this->get('/document')->getContent());
    }

    public function test_a_page_that_does_not_is_left_alone(): void
    {
        $this->assertStringNotContainsString(
            $this->hint(),
            (string) $this->get('/plain')->getContent(),
            'An 82 kB preload on every page is worse than the loading slot it removes from one.'
        );
    }

    public function test_nothing_is_hinted_at_when_the_feature_is_off(): void
    {
        config()->set('pwax.json.enabled', false);

        $this->assertStringNotContainsString(
            'rel="preload" as="script" href="/__pwax__/pwax-json.js',
            (string) $this->get('/document')->getContent()
        );
    }

    /**
     * The whole tag, not just the filename.
     *
     * The renderer's URL is in the configuration island on every page — that is how the
     * runtime knows where to fetch it from when a document finally needs one — so a test
     * looking for the bare filename passes on a page that was never hinted at.
     */
    private function hint(): string
    {
        return '<link rel="preload" as="script" href="'
            . $this->app->make(Shell::class)->jsonRuntimeUrl() . '">';
    }
}
