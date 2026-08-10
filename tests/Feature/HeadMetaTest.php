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
        $this->assertStringContainsString('name="twitter:card" content="summary"', $html);
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

    public function test_a_page_that_declares_nothing_sends_no_head_in_its_payload(): void
    {
        // The runtime clears what the previous page set. Sending an empty head for every
        // page would wipe the application-wide description on the first navigation.
        $this->assertArrayNotHasKey('head', $this->payload('/plain'));
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
