<?php

namespace Mxent\Pwax\Compiler;

/**
 * Adds a `data-pwax-{scope}` attribute to every element a component's template renders,
 * so the selectors rewritten by {@see StyleScoper} have something to match.
 *
 * This is a character scanner rather than an HTML parser, and deliberately so. Vue
 * template strings are not HTML: `<MyComponent />` is a self-closing custom element to
 * Vue but an unclosed start tag to an HTML parser, which would silently swallow every
 * following sibling. Running templates through `DOMDocument` or the browser's own
 * parser would therefore corrupt perfectly valid components.
 *
 * Quote state is tracked while inside a tag, so attribute values containing `>` — for
 * example `:class="{ active: a > b }"` — do not terminate the tag early.
 *
 * Tags that render no element of their own are skipped: `<template>` (Vue structural
 * directives) and `<slot>` (an outlet, whose content belongs to the parent's scope).
 */
class TemplateStamper
{
    private const SKIPPED_TAGS = ['template', 'slot', 'component', 'transition', 'transition-group', 'keep-alive', 'teleport', 'suspense'];

    public function stamp(string $template, string $scopeId): string
    {
        if (trim($template) === '') {
            return $template;
        }

        $attribute = ' data-pwax-' . $scopeId;
        $out = '';
        $length = strlen($template);
        $i = 0;

        while ($i < $length) {
            if ($template[$i] !== '<') {
                $out .= $template[$i];
                $i++;

                continue;
            }

            // Comments, CDATA and doctypes are copied through untouched.
            if (str_starts_with(substr($template, $i, 4), '<!--')) {
                $end = strpos($template, '-->', $i + 4);
                $end = $end === false ? $length : $end + 3;
                $out .= substr($template, $i, $end - $i);
                $i = $end;

                continue;
            }

            $name = $this->tagNameAt($template, $i);

            if ($name === null) {
                $out .= $template[$i];
                $i++;

                continue;
            }

            $end = $this->endOfTag($template, $i);
            $tag = substr($template, $i, $end - $i);

            $out .= in_array(strtolower($name), self::SKIPPED_TAGS, true)
                ? $tag
                : $this->insert($tag, $attribute);

            $i = $end;
        }

        return $out;
    }

    /**
     * Return the tag name if an element start tag begins at $offset, else null.
     */
    private function tagNameAt(string $template, int $offset): ?string
    {
        // Closing tags carry no attributes.
        if (($template[$offset + 1] ?? '') === '/') {
            return null;
        }

        return preg_match('/^<([A-Za-z][A-Za-z0-9._-]*)/', substr($template, $offset, 64), $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * Return the offset just past the `>` that closes the tag starting at $offset.
     */
    private function endOfTag(string $template, int $offset): int
    {
        $length = strlen($template);
        $quote = null;

        for ($i = $offset + 1; $i < $length; $i++) {
            $char = $template[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '>') {
                return $i + 1;
            }
        }

        return $length;
    }

    /**
     * Insert the scope attribute at the end of a start tag, ahead of any `/`.
     */
    private function insert(string $tag, string $attribute): string
    {
        $inner = rtrim(substr($tag, 0, -1));

        if (str_ends_with($inner, '/')) {
            return rtrim(substr($inner, 0, -1)) . $attribute . ' />';
        }

        return $inner . $attribute . '>';
    }
}
