<?php

namespace Mxent\Pwax\Pwa;

use Illuminate\Contracts\Config\Repository as Config;
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
    public function __construct(private readonly Config $config) {}

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

        if (! $this->config->get('pwax.head.open_graph', true)) {
            return array_values($meta);
        }

        $declared = [];

        foreach ($meta as $tag) {
            $declared[$tag['attribute'] . ':' . $tag['key']] = true;
        }

        $derived = [
            ['property', 'og:type', $this->string('pwax.head.open_graph_type') ?? 'website'],
            ['property', 'og:site_name', $this->string('pwax.manifest.name')],
            ['property', 'og:title', $title],
            ['property', 'og:description', $description],
            ['property', 'og:url', $canonical],
            ['name', 'twitter:card', $this->string('pwax.head.twitter_card') ?? 'summary'],
            ['name', 'twitter:title', $title],
            ['name', 'twitter:description', $description],
        ];

        foreach ($derived as [$attribute, $key, $content]) {
            if ($content === null || $content === '' || isset($declared[$attribute . ':' . $key])) {
                continue;
            }

            $meta[] = ['attribute' => $attribute, 'key' => $key, 'content' => $content];
        }

        return array_values($meta);
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
