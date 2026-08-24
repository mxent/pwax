<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Tests\TestCase;

/**
 * Per-page document metadata.
 *
 * A browser replaces the head on a real navigation. A router does not — so a title that
 * moves with the route and a description that stays behind is worse than setting neither,
 * because the wrong answer outlives the missing one. Everything a page declares therefore
 * has to travel twice: as tags in the document, and in the payload the runtime applies.
 * These assert the two agree.
 */
class HeadMetaTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/plain', fn () => pwaxRender('pages.home'));

            $router->get('/post', fn () => pwaxRender('pages.home')
                ->title('Hello world')
                ->description('A first post.')
                ->canonical('https://example.test/post')
                ->property('og:image', 'https://example.test/og.png')
                ->meta('robots', 'noindex'));
        });
    }

    private function payload(string $uri): array
    {
        $payload = $this->withHeaders($this->componentHeaders())->get($uri)->json();

        // `withHeaders` persists on the test case, so without this the next `get()` in the
        // same test asks for a payload as well and the comparison below is JSON against
        // JSON — which passes for the wrong reason.
        $this->flushHeaders();

        return $payload;
    }

    public function test_a_page_emits_its_description_and_canonical(): void
    {
        $html = (string) $this->get('/post')->getContent();

        $this->assertStringContainsString('<meta name="description" content="A first post.">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/post">', $html);
    }

    public function test_open_graph_is_derived_from_what_the_page_declared(): void
    {
        config()->set('pwax.manifest.name', 'Acme');

        $html = (string) $this->get('/post')->getContent();

        $this->assertStringContainsString('property="og:title" content="Hello world"', $html);
        $this->assertStringContainsString('property="og:description" content="A first post."', $html);
        $this->assertStringContainsString('property="og:url" content="https://example.test/post"', $html);
        $this->assertStringContainsString('property="og:site_name" content="Acme"', $html);
        $this->assertStringContainsString('property="og:type" content="website"', $html);
    }

    public function test_the_twitter_card_follows_the_image(): void
    {
        // `/post` declares an og:image. A 'summary' card alongside a 1200x630 image throws
        // most of that artwork away, and 'summary_large_image' with no image renders as a
        // bare summary anyway — so with `head.twitter_card` left null the card follows the
        // one fact that decides which of the two is right.
        $this->assertStringContainsString(
            'name="twitter:card" content="summary_large_image"',
            (string) $this->get('/post')->getContent()
        );

        $this->assertStringContainsString(
            'name="twitter:card" content="summary"',
            (string) $this->get('/plain')->getContent()
        );
    }

    public function test_a_configured_twitter_card_wins_over_the_derived_one(): void
    {
        config()->set('pwax.head.twitter_card', 'summary');

        $this->assertStringContainsString(
            'name="twitter:card" content="summary"',
            (string) $this->get('/post')->getContent()
        );
    }

    public function test_a_declared_tag_is_not_overwritten_by_a_derived_one(): void
    {
        $this->app['router']->middleware('web')->get('/own', fn () => pwaxRender('pages.home')
            ->title('Derived')
            ->property('og:title', 'Mine'));

        $html = (string) $this->get('/own')->getContent();

        $this->assertStringContainsString('property="og:title" content="Mine"', $html);
        $this->assertStringNotContainsString('property="og:title" content="Derived"', $html);
    }

    public function test_derivation_can_be_turned_off(): void
    {
        config()->set('pwax.head.open_graph', false);

        $html = (string) $this->get('/post')->getContent();

        $this->assertStringNotContainsString('og:title', $html);

        // The page's own tags survive; only the derived ones go.
        $this->assertStringContainsString('name="robots" content="noindex"', $html);
    }

    public function test_nothing_is_invented_from_a_value_that_does_not_exist(): void
    {
        config()->set('pwax.manifest.name', '');
        config()->set('pwax.manifest.description', null);
        config()->set('pwax.head.title', null);
        config()->set('pwax.head.description', null);

        $html = (string) $this->get('/plain')->getContent();

        $this->assertStringNotContainsString('og:title', $html);
        $this->assertStringNotContainsString('og:description', $html);
        $this->assertStringNotContainsString('og:site_name', $html);
    }

    public function test_the_payload_carries_the_same_metadata_as_the_document(): void
    {
        config()->set('pwax.manifest.name', 'Acme');

        $payload = $this->payload('/post');
        $html = (string) $this->get('/post')->getContent();

        $this->assertSame('A first post.', $payload['head']['description']);
        $this->assertSame('https://example.test/post', $payload['head']['canonical']);

        // Every tag the payload names is a tag the document rendered. If these two ever
        // drift, a page describes itself one way on a reload and another after a link.
        foreach ($payload['head']['meta'] as $tag) {
            $this->assertStringContainsString(
                sprintf('%s="%s" content="%s"', $tag['attribute'], $tag['key'], $tag['content']),
                $html
            );
        }
    }

    /**
     * The page that declares nothing is the one that has to carry metadata.
     *
     * A router leaves the head as the previous page left it, so a page sending nothing
     * inherits whatever the last one said and keeps it for the rest of the session.
     * Omitting the head on the reasoning that an empty one would wipe the
     * application-wide description gets this exactly backwards: it produces the drift it
     * was meant to prevent.
     */
    public function test_a_page_that_declares_nothing_still_sends_a_head(): void
    {
        config()->set('pwax.manifest.name', 'Acme');
        config()->set('pwax.manifest.description', 'The Acme application.');

        $payload = $this->payload('/plain');

        $this->assertArrayHasKey('head', $payload);

        // The resolved fallbacks, not the previous page's values.
        $this->assertSame('Acme', $payload['head']['title']);
        $this->assertSame('The Acme application.', $payload['head']['description']);

        // No canonical, so the runtime removes whatever the last page set. A canonical URL
        // that outlives its page is the specific mistake worth being loud about.
        $this->assertArrayNotHasKey('canonical', $payload['head']);
    }

    /**
     * The title a navigation applies and the title a reload renders must be the same
     * string. They were not: a page with no title of its own sent none, so the tab kept
     * the previous page's — and so did the live region the runtime announces after every
     * navigation, which reads `document.title` out to a screen reader.
     */
    public function test_an_untitled_page_carries_the_same_title_a_reload_would_render(): void
    {
        config()->set('pwax.manifest.name', 'Acme');

        $this->assertSame('Acme', $this->payload('/plain')['title']);

        $this->get('/plain')->assertSee('<title>Acme</title>', false);
    }

    /**
     * The template must not be applied to the fallback — ':title · Acme' against a
     * fallback of 'Acme' renders 'Acme · Acme' — and that has to hold in the payload as
     * well as in the document, now that the payload always carries a title.
     */
    public function test_the_fallback_title_in_the_payload_is_not_run_through_the_template(): void
    {
        config()->set('pwax.manifest.name', 'Acme');
        config()->set('pwax.head.title_template', ':title · Acme');

        $this->assertSame('Acme', $this->payload('/plain')['title']);
        $this->assertSame('Hello world · Acme', $this->payload('/post')['title']);
    }

    public function test_a_page_image_is_emitted_for_both_open_graph_and_twitter(): void
    {
        $this->app['router']->middleware('web')->get('/card', fn () => pwaxRender('pages.home')
            ->image('https://cdn.example.test/cover.png'));

        $html = (string) $this->get('/card')->getContent();

        // Both, because a page that has one and not the other is a page whose link preview
        // depends on which service is unfurling it.
        $this->assertStringContainsString('property="og:image" content="https://cdn.example.test/cover.png"', $html);
        $this->assertStringContainsString('name="twitter:image" content="https://cdn.example.test/cover.png"', $html);
    }

    public function test_a_site_relative_image_is_made_absolute(): void
    {
        config()->set('pwax.head.image', '/img/og.png');

        // A scraper reading Open Graph does not necessarily have the document to resolve a
        // relative URL against, and the failure is a preview with no image rather than an
        // error — so nobody finds out until someone shares a link.
        $this->assertStringContainsString(
            'property="og:image" content="http://localhost/img/og.png"',
            (string) $this->get('/plain')->getContent()
        );
    }

    public function test_an_absolute_image_is_left_exactly_as_written(): void
    {
        config()->set('pwax.head.image', 'https://cdn.example.test/og.png');

        $this->assertStringContainsString(
            'property="og:image" content="https://cdn.example.test/og.png"',
            (string) $this->get('/plain')->getContent()
        );
    }

    public function test_the_page_image_wins_over_the_configured_one(): void
    {
        config()->set('pwax.head.image', 'https://cdn.example.test/default.png');

        $this->app['router']->middleware('web')->get('/card', fn () => pwaxRender('pages.home')
            ->image('https://cdn.example.test/own.png'));

        $html = (string) $this->get('/card')->getContent();

        $this->assertStringContainsString('content="https://cdn.example.test/own.png"', $html);
        $this->assertStringNotContainsString('default.png', $html);
    }

    public function test_a_configured_robots_directive_applies_to_every_page(): void
    {
        // The reason this is a config key and not just a per-route call: a staging
        // deployment says it once rather than on every route, and a route added later
        // cannot forget.
        config()->set('pwax.head.robots', 'noindex, nofollow');

        $this->assertStringContainsString(
            'name="robots" content="noindex, nofollow"',
            (string) $this->get('/plain')->getContent()
        );
    }

    public function test_a_page_robots_directive_wins_over_the_configured_one(): void
    {
        config()->set('pwax.head.robots', 'noindex');

        $this->app['router']->middleware('web')->get('/indexed', fn () => pwaxRender('pages.home')
            ->robots('index, follow'));

        $html = (string) $this->get('/indexed')->getContent();

        $this->assertStringContainsString('name="robots" content="index, follow"', $html);
        $this->assertStringNotContainsString('content="noindex"', $html);
    }

    public function test_robots_survives_open_graph_being_switched_off(): void
    {
        // It is not an Open Graph tag and is not derived from one. Losing it with the rest
        // would mean an application that turns derivation off silently starts indexing a
        // staging deployment.
        config()->set('pwax.head.open_graph', false);
        config()->set('pwax.head.robots', 'noindex');

        $this->assertStringContainsString(
            'name="robots" content="noindex"',
            (string) $this->get('/plain')->getContent()
        );
    }

    public function test_the_open_graph_locale_follows_the_application(): void
    {
        $this->app->setLocale('fr_CA');

        // Open Graph asks for `fr_CA`, HTML for `fr-CA`. The normalisation is the whole
        // reason this is derived rather than left to the application to remember.
        $this->assertStringContainsString(
            'property="og:locale" content="fr_CA"',
            (string) $this->get('/plain')->getContent()
        );
    }

    public function test_structured_data_is_rendered_as_its_own_block(): void
    {
        $this->app['router']->middleware('web')->get('/article', fn () => pwaxRender('pages.home')
            ->jsonLd(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'Hello'])
            ->jsonLd(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList']));

        $html = (string) $this->get('/article')->getContent();

        // One block per claim, which is what Google's documentation asks for.
        $this->assertSame(2, substr_count($html, '<script type="application/ld+json"'));
        $this->assertStringContainsString('"@type":"Article"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
    }

    public function test_structured_data_cannot_close_its_own_script_block(): void
    {
        $this->app['router']->middleware('web')->get('/injected', fn () => pwaxRender('pages.home')
            ->jsonLd(['headline' => '</script><script>alert(1)</script>']));

        $html = (string) $this->get('/injected')->getContent();

        // This is the one place in the head where a value from the database is written as
        // markup rather than as an attribute, so the escaping has to hold against content
        // nobody reviewed.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('\u003C/script\u003E', $html);
    }

    public function test_structured_data_carries_the_nonce(): void
    {
        config()->set('pwax.csp.nonce', 'n0nce-value');

        $this->app['router']->middleware('web')->get('/article', fn () => pwaxRender('pages.home')
            ->jsonLd(['@type' => 'Article']));

        // A browser applies `script-src` to a `<script>` by its tag, not its `type`, so an
        // un-nonced ld+json block is refused under a strict policy — silently, and only in
        // production.
        $this->assertStringContainsString(
            '<script type="application/ld+json" data-pwax-head nonce="n0nce-value">',
            (string) $this->get('/article')->getContent()
        );
    }

    public function test_a_page_replaces_the_configured_structured_data_rather_than_adding_to_it(): void
    {
        config()->set('pwax.head.json_ld', ['@type' => 'Organization', 'name' => 'Acme']);

        $this->app['router']->middleware('web')->get('/article', fn () => pwaxRender('pages.home')
            ->jsonLd(['@type' => 'Article']));

        $html = (string) $this->get('/article')->getContent();

        // Emitting both against one URL says the page is an Article and an Organization.
        $this->assertStringContainsString('"@type":"Article"', $html);
        $this->assertStringNotContainsString('Organization', $html);

        // The site-wide default still reaches a page that claims nothing of its own.
        $this->assertStringContainsString('Organization', (string) $this->get('/plain')->getContent());
    }

    public function test_structured_data_that_will_not_encode_is_dropped_from_both_paths(): void
    {
        $this->app['router']->middleware('web')->get('/broken', fn () => pwaxRender('pages.home')
            // Not valid UTF-8. `json_encode` returns false for it, and the two paths this
            // metadata travels used to disagree about that: the view wrote an empty
            // `<script type="application/ld+json">` and the payload's JsonResponse threw, so
            // the same page rendered on a reload and returned a 500 after a link.
            ->jsonLd(['@type' => 'Article', 'headline' => "\xB1\x31"])
            ->jsonLd(['@type' => 'BreadcrumbList']));

        $html = (string) $this->get('/broken')->getContent();

        $this->assertSame(1, substr_count($html, '<script type="application/ld+json"'));
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);

        $this->assertSame(
            [['@type' => 'BreadcrumbList']],
            $this->payload('/broken')['head']['jsonLd']
        );
    }

    public function test_a_malformed_alternate_is_dropped_rather_than_emitted_as_hreflang_zero(): void
    {
        // `['fr']` where `['fr' => '/fr']` was meant. No language tag is all digits, so the
        // array index is not one — and `hreflang="0"` is worse than nothing.
        config()->set('pwax.head.alternates', ['fr']);

        $this->assertStringNotContainsString(
            'rel="alternate"',
            (string) $this->get('/plain')->getContent()
        );
    }

    public function test_alternate_links_are_emitted_for_every_locale(): void
    {
        $this->app['router']->middleware('web')->get('/localised', fn () => pwaxRender('pages.home')
            ->alternate('en', 'https://example.test/post')
            ->alternate('fr', 'https://example.test/fr/post')
            ->alternate('x-default', 'https://example.test/post'));

        $html = (string) $this->get('/localised')->getContent();

        $this->assertStringContainsString('<link rel="alternate" hreflang="fr" href="https://example.test/fr/post"', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://example.test/post"', $html);
    }

    public function test_configured_alternates_accept_the_map_spelling(): void
    {
        config()->set('pwax.head.alternates', ['en' => 'https://example.test', 'fr' => '/fr']);

        $html = (string) $this->get('/plain')->getContent();

        $this->assertStringContainsString('hreflang="en" href="https://example.test"', $html);

        // Relative, so made absolute for the same reason the image is.
        $this->assertStringContainsString('hreflang="fr" href="http://localhost/fr"', $html);
    }

    public function test_the_payload_carries_the_structured_data_and_the_alternates(): void
    {
        $this->app['router']->middleware('web')->get('/article', fn () => pwaxRender('pages.home')
            ->jsonLd(['@type' => 'Article'])
            ->alternate('fr', 'https://example.test/fr'));

        $payload = $this->payload('/article');

        // Stale structured data is not a missing rich result, it is a wrong one — so it has
        // to travel with the navigation like everything else in the head does.
        $this->assertSame([['@type' => 'Article']], $payload['head']['jsonLd']);
        $this->assertSame(
            [['hreflang' => 'fr', 'href' => 'https://example.test/fr']],
            $payload['head']['alternates']
        );
    }

    public function test_managed_tags_are_marked_so_the_runtime_can_replace_them(): void
    {
        $html = (string) $this->get('/post')->getContent();

        // Without the marker the runtime cannot tell its own tags from the ones an
        // application pushed into @stack('pwax-head'), and would remove both.
        $this->assertStringContainsString('name="robots" content="noindex" data-pwax-head>', $html);
    }

    public function test_the_title_template_applies_to_a_page_title_only(): void
    {
        config()->set('pwax.head.title_template', ':title · Acme');
        config()->set('pwax.head.title', 'Acme');

        $this->assertStringContainsString('<title>Hello world · Acme</title>', (string) $this->get('/post')->getContent());

        // Not ':title · Acme' against a fallback of 'Acme', which reads 'Acme · Acme'.
        $this->assertStringContainsString('<title>Acme</title>', (string) $this->get('/plain')->getContent());
    }

    public function test_the_offline_shell_carries_no_page_metadata(): void
    {
        config()->set('pwax.service_worker.enabled', true);

        $html = (string) $this->get('/__pwax__/shell')->getContent();

        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('og:url', $html);
    }
}
