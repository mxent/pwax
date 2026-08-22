<?php

namespace Mxent\Pwax\Pwa;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Mxent\Pwax\Data\Head;

/**
 * Resolves a page's declared metadata against the application's defaults.
 *
 * One place, because the answer is needed in two: the shell view renders it as tags, and
 * `ComponentResponse` puts it in the payload so the runtime can apply it on a client-side
 * navigation. If those two disagreed, a page would carry one description on a reload and
 * another after a link — which is the sort of difference nobody notices until a crawler
 * or a link preview does.
 */
class HeadMeta
{
    public function __construct(
        private readonly Config $config,
        // Optional so that a `new HeadMeta($config)` in a test or a published service
        // provider keeps working. Without one, a relative image or alternate URL is passed
        // through as written rather than made absolute.
        private readonly ?UrlGenerator $url = null,
        private readonly ?Application $app = null,
    ) {}

    /**
     * The metadata for a page, with defaults filled in and Open Graph derived.
     */
    public function resolve(?Head $page = null): Head
    {
        $page ??= new Head;

        $title = $this->title($page->title);
        $description = $page->description
            ?? $this->string('pwax.head.description')
            ?? $this->string('pwax.manifest.description');
        $canonical = $page->canonical;

        return new Head(
            title: $title,
            description: $description,
            canonical: $canonical,
            meta: $this->meta($page, $title, $description, $canonical),
            jsonLd: $this->jsonLd($page),
            alternates: $this->alternates($page),
        );
    }

    /**
     * The document title, with `head.title_template` applied.
     *
     * The template is deliberately skipped when the page set no title of its own:
     * ':title · Acme' against a fallback of 'Acme' would render 'Acme · Acme'.
     */
    private function title(?string $pageTitle): ?string
    {
        if ($pageTitle === null) {
            return $this->string('pwax.head.title') ?? $this->string('pwax.manifest.name');
        }

        $template = $this->string('pwax.head.title_template');

        return $template !== null && str_contains($template, ':title')
            ? str_replace(':title', $pageTitle, $template)
            : $pageTitle;
    }

    /**
     * The page's own tags, plus the Open Graph and Twitter ones derived from them.
     *
     * Derivation never overwrites: a page that set `og:title` by hand keeps it. Only the
     * gaps are filled, and only from values that already exist — this invents nothing.
     *
     * @return list<array{attribute: string, key: string, content: string}>
     */
    private function meta(Head $page, ?string $title, ?string $description, ?string $canonical): array
    {
        $meta = $page->meta;

        $declared = [];

        foreach ($meta as $tag) {
            $declared[$tag['attribute'] . ':' . $tag['key']] = $tag['content'];
        }

        // `robots` is not an Open Graph tag and is not derived from anything, so it is
        // applied whether or not `head.open_graph` is on. It is the one directive whose
        // absence is expensive in exactly the case an application most wants it — a staging
        // deployment that should not be indexed sets `pwax.head.robots` once, and every page
        // that has not overridden it carries the directive.
        $robots = $this->string('pwax.head.robots');

        if ($robots !== null && ! isset($declared['name:robots'])) {
            $meta[] = ['attribute' => 'name', 'key' => 'robots', 'content' => $robots];
            $declared['name:robots'] = $robots;
        }

        if (! $this->config->get('pwax.head.open_graph', true)) {
            return array_values($meta);
        }

        // The page's own image wins; `head.image` is the application-wide fallback, which is
        // what a site with one social card wants to set once rather than on every route.
        $image = $this->absolute($declared['property:og:image'] ?? $this->string('pwax.head.image'));

        $derived = [
            ['property', 'og:type', $this->string('pwax.head.open_graph_type') ?? 'website'],
            ['property', 'og:site_name', $this->string('pwax.manifest.name')],
            ['property', 'og:title', $title],
            ['property', 'og:description', $description],
            ['property', 'og:url', $canonical],
            ['property', 'og:locale', $this->locale()],
            ['property', 'og:image', $image],
            ['name', 'twitter:card', $this->twitterCard($image)],
            ['name', 'twitter:title', $title],
            ['name', 'twitter:description', $description],
            // Twitter reads `og:image` when there is no `twitter:image`, so this is
            // belt-and-braces rather than load-bearing — but the two are allowed to differ
            // and an application that sets one by hand should not silently get the other.
            ['name', 'twitter:image', $image],
        ];

        foreach ($derived as [$attribute, $key, $content]) {
            if ($content === null || $content === '' || isset($declared[$attribute . ':' . $key])) {
                continue;
            }

            $meta[] = ['attribute' => $attribute, 'key' => $key, 'content' => $content];
        }

        return $this->withAbsoluteUrls($meta);
    }

    /**
     * Tags whose content is a URL that has to be absolute.
     *
     * Open Graph and Twitter are read by scrapers that fetch the tag and not the document,
     * so a site-relative value in one of these is resolved against nothing. The practical
     * failure is a link preview with no image — never an error, and not visible to anyone
     * who does not go and share a link.
     *
     * Applied to every tag rather than only the derived ones: `->property('og:image', …)`
     * with `asset()` on the other side of it is the spelling people reach for first, and it
     * has exactly the same problem.
     *
     * @param  list<array{attribute: string, key: string, content: string}>  $meta
     * @return list<array{attribute: string, key: string, content: string}>
     */
    private function withAbsoluteUrls(array $meta): array
    {
        $urlKeys = [
            'og:image' => true,
            'og:image:url' => true,
            'og:image:secure_url' => true,
            'og:url' => true,
            'og:audio' => true,
            'og:video' => true,
            'twitter:image' => true,
        ];

        foreach ($meta as $index => $tag) {
            if (isset($urlKeys[$tag['key']])) {
                $meta[$index]['content'] = (string) $this->absolute($tag['content']);
            }
        }

        return array_values($meta);
    }

    /**
     * `twitter:card`, configured or derived.
     *
     * A configured value always wins. Left null — which is the package's own default — the
     * card follows the image: a card declaring `summary_large_image` with no image renders
     * as a bare `summary` anyway, and a `summary` alongside a 1200×630 image throws away
     * most of the artwork it was given.
     *
     * The published `config/pwax.php` written before this existed carries a literal
     * 'summary', so an application that has published its config keeps exactly the tag it
     * had until it chooses otherwise.
     */
    private function twitterCard(?string $image): string
    {
        return $this->string('pwax.head.twitter_card')
            ?? ($image !== null ? 'summary_large_image' : 'summary');
    }

    /**
     * `og:locale`, in the underscored form Open Graph asks for.
     *
     * Derived from the application locale, like the manifest's `lang`, so a localised
     * application declares this in one place rather than three. Open Graph wants `en_US`
     * where HTML wants `en-US`, which is the one difference worth normalising here.
     */
    private function locale(): ?string
    {
        $locale = $this->string('pwax.head.locale') ?? $this->app?->getLocale();

        if (! is_string($locale) || $locale === '') {
            return null;
        }

        return str_replace('-', '_', $locale);
    }

    /**
     * Structured data for this page, or the application's default when it declares none.
     *
     * Not merged. A page that describes itself as an `Article` and a site that describes
     * itself as an `Organization` are two different claims about two different things, and
     * concatenating them produces a document asserting both about the same URL. The site
     * default is what a page gets when it has said nothing; a page that has spoken replaces
     * it. An application that wants both emits the site-wide graph from
     * `@stack('pwax-head')`, which is a document-level concern and outlives the navigation.
     *
     * @return list<array<string, mixed>>
     */
    private function jsonLd(Head $page): array
    {
        if ($page->jsonLd !== []) {
            return $page->jsonLd;
        }

        /** @var mixed $configured */
        $configured = $this->config->get('pwax.head.json_ld');

        if (! is_array($configured) || $configured === []) {
            return [];
        }

        // One object, or a list of them. Both spellings read naturally in a config file and
        // guessing wrong costs a document with a `0` key in it.
        /** @var list<array<string, mixed>> $entries */
        $entries = array_is_list($configured)
            ? array_values(array_filter($configured, 'is_array'))
            : [$configured];

        return $entries;
    }

    /**
     * `rel="alternate"` links for this page, or the application's default.
     *
     * Accepts the map spelling a config file wants (`['en' => '/', 'fr' => '/fr']`) and the
     * list spelling a route builds (`[['hreflang' => 'en', 'href' => '/']]`), because these
     * are written in two different places by two different people.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    private function alternates(Head $page): array
    {
        $declared = $page->alternates !== []
            ? $page->alternates
            : (array) $this->config->get('pwax.head.alternates', []);

        $links = [];

        foreach ($declared as $key => $value) {
            $hreflang = is_array($value) ? (string) ($value['hreflang'] ?? '') : (string) $key;
            $href = is_array($value) ? (string) ($value['href'] ?? '') : (string) $value;
            $href = (string) $this->absolute($href);

            if ($hreflang === '' || $href === '') {
                continue;
            }

            $links[] = ['hreflang' => $hreflang, 'href' => $href];
        }

        return $links;
    }

    /**
     * Make a site-relative URL absolute.
     *
     * A crawler resolves `<link rel="canonical">` against the document, but Open Graph and
     * `hreflang` are read by scrapers that do not always have the document to resolve
     * against — the specification asks for an absolute URL and the practical failure of a
     * relative one is a link preview with no image at all. Anything already carrying a
     * scheme, or protocol-relative, or a data URI, is left exactly as written.
     */
    private function absolute(?string $url): ?string
    {
        if ($url === null || $url === '' || $this->url === null) {
            return $url;
        }

        foreach (['http://', 'https://', '//', 'data:'] as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return $url;
            }
        }

        return $this->url->to($url);
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
