<?php

namespace Mxent\Pwax\Compiler;

use RuntimeException;

/**
 * Splits rendered Blade output into the parts of a Vue single-file component.
 *
 * The parser is deliberately shallow — it is a block splitter, not an HTML parser — but
 * it is depth-aware for `<template>` and attribute-tolerant everywhere, which is more than
 * a `strpos`/`strrpos` scan can manage. In particular:
 *
 *   - `<template lang="html">` and `<script setup>` are matched, not skipped.
 *   - Nested `<template v-if>` / `<template #slot>` tags do not truncate the root
 *     template at the wrong closing tag.
 *   - `<script>` and `<style>` blocks are removed before the template is located, so
 *     a `"<template>"` string inside JavaScript cannot be mistaken for markup.
 *
 * One HTML rule is inherited rather than worked around: a literal `</script>` inside a
 * JavaScript string terminates the block. Write `<\/script>`, exactly as you would in
 * a plain HTML page.
 */
class BlockExtractor
{
    /**
     * Return the inner HTML of the outermost `<template>` block.
     */
    public function template(string $contents): string
    {
        $markup = $this->withoutBlocks($contents);

        if (stripos($markup, '<template') === false) {
            return '';
        }

        $matches = $this->matchAll('/<(\/?)template\b([^>]*)>/i', $markup, PREG_OFFSET_CAPTURE);

        if ($matches === []) {
            return '';
        }

        $depth = 0;
        $start = null;

        foreach ($matches as $match) {
            $tag = (string) $match[0][0];
            $offset = (int) $match[0][1];
            $isClosing = ((string) $match[1][0]) === '/';
            $isSelfClosing = ! $isClosing && str_ends_with(rtrim(substr($tag, 0, -1)), '/');

            if ($isSelfClosing) {
                continue;
            }

            if (! $isClosing) {
                if ($depth === 0) {
                    $start = $offset + strlen($tag);
                }
                $depth++;

                continue;
            }

            $depth--;

            if ($depth === 0 && $start !== null) {
                return substr($markup, $start, $offset - $start);
            }
        }

        return '';
    }

    /**
     * Return every inline `<script>` block, ignoring those with a `src` attribute.
     *
     * @return list<array{content: string, attributes: array<string, string|true>}>
     */
    public function scripts(string $contents): array
    {
        return $this->blocks('script', 'src', $contents);
    }

    /**
     * Return every inline `<style>` block, ignoring those with an `href` attribute.
     *
     * @return list<array{content: string, attributes: array<string, string|true>}>
     */
    public function styles(string $contents): array
    {
        return $this->blocks('style', 'href', $contents);
    }

    /**
     * Return the `src` of every `<script src="...">` tag, in document order and deduped.
     *
     * @return list<string>
     */
    public function externalScripts(string $contents): array
    {
        return $this->attributeValues('/<script\b[^>]*?\bsrc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)[^>]*>/i', $contents);
    }

    /**
     * Return the `href` of every stylesheet `<link>` tag, in document order and deduped.
     *
     * @return list<string>
     */
    public function externalStyles(string $contents): array
    {
        $hrefs = [];

        foreach ($this->matchAll('/<link\b[^>]*>/i', $contents) as $match) {
            $attributes = $this->parseAttributes($match[0]);
            $rel = $attributes['rel'] ?? null;

            // Only stylesheets. `<link rel="manifest">`, preloads and icons are not ours
            // to inject, and sweeping them into the payload would inject them anyway.
            if (! is_string($rel) || strtolower(trim($rel)) !== 'stylesheet') {
                continue;
            }

            $href = $attributes['href'] ?? null;

            if (is_string($href) && $href !== '') {
                $hrefs[] = $href;
            }
        }

        return array_values(array_unique($hrefs));
    }

    /**
     * Parse an HTML start tag's attributes into name => value pairs.
     *
     * Valueless attributes (`scoped`, `defer`, `setup`) map to `true`.
     *
     * @return array<string, string|true>
     */
    public function parseAttributes(string $tag): array
    {
        // Trim `<name` from the front and `>` / `/>` from the back.
        $inner = preg_replace('/^<\s*\/?[A-Za-z][A-Za-z0-9-]*/', '', trim($tag)) ?? '';
        $inner = rtrim(rtrim(trim($inner), '>'), '/');

        $attributes = [];

        foreach ($this->matchAll('/([A-Za-z_:@][-A-Za-z0-9_:.@]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/', $inner) as $match) {
            $name = strtolower($match[1]);

            if (! isset($match[2]) || $match[2] === '') {
                $attributes[$name] = true;

                continue;
            }

            $attributes[$name] = html_entity_decode(
                trim($match[2], "\"'"),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }

        return $attributes;
    }

    /**
     * Extract inline blocks for a tag, skipping any that reference an external resource.
     *
     * @return list<array{content: string, attributes: array<string, string|true>}>
     */
    private function blocks(string $tag, string $externalAttribute, string $contents): array
    {
        $quoted = preg_quote($tag, '/');
        $pattern = '/<' . $quoted . '\b([^>]*)>([\s\S]*?)<\/' . $quoted . '\s*>/i';

        $blocks = [];

        foreach ($this->matchAll($pattern, $contents) as $match) {
            $attributes = $this->parseAttributes('<' . $tag . $match[1] . '>');

            if (isset($attributes[$externalAttribute])) {
                continue;
            }

            if (trim($match[2]) === '') {
                continue;
            }

            $blocks[] = ['content' => $match[2], 'attributes' => $attributes];
        }

        return $blocks;
    }

    /**
     * Strip `<script>` and `<style>` blocks so template detection only sees markup.
     */
    private function withoutBlocks(string $contents): string
    {
        return (string) preg_replace(
            [
                '/<script\b[^>]*>[\s\S]*?<\/script\s*>/i',
                '/<style\b[^>]*>[\s\S]*?<\/style\s*>/i',
            ],
            '',
            $contents
        );
    }

    /**
     * @return list<string>
     */
    private function attributeValues(string $pattern, string $contents): array
    {
        $values = [];

        foreach ($this->matchAll($pattern, $contents) as $match) {
            $value = trim($match[1], "\"'");

            if ($value !== '') {
                $values[] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * `preg_match_all` that fails loudly instead of silently returning nothing when the
     * backtrack or recursion limit is hit on a large view.
     *
     * @return list<array<int, mixed>>
     */
    private function matchAll(string $pattern, string $subject, int $flags = 0): array
    {
        $result = preg_match_all($pattern, $subject, $matches, PREG_SET_ORDER | $flags);

        if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
            throw new RuntimeException(
                'Pwax failed to parse component blocks: ' . preg_last_error_msg()
                . '. The view may exceed pcre.backtrack_limit.'
            );
        }

        /** @var list<array<int, mixed>> $matches */
        return array_values($matches);
    }
}
