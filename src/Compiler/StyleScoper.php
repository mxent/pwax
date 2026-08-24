<?php

namespace Mxent\Pwax\Compiler;

/**
 * Rewrites `<style scoped>` rules so they only match the component that declared them.
 *
 * Every selector gains a `[data-pwax-{scope}]` attribute qualifier, and the component's
 * template roots are stamped with the matching attribute, so a rule can only ever match
 * inside the component it was written in. The scope id is derived from the view's own
 * contents, which keeps it stable between requests and changes it when the view changes.
 *
 * Scoping at compile time is what lets the runtime reference-count style elements rather
 * than clearing them wholesale: injected globally, a component's styles could only be torn
 * down by sweeping the document, which takes the styles of still-mounted imported
 * components with them. Scoped, a component's CSS cannot reach its siblings to begin with.
 *
 * Escape hatches, keeping the names Vue authors already type:
 *   - `:deep(.child)`  — the part inside is left unscoped, so it can reach child components.
 *   - `:global(.thing)` — the selector is emitted verbatim, with no scoping at all.
 */
class StyleScoper
{
    /**
     * At-rules whose body is a list of style rules, and so must be scoped recursively.
     */
    private const NESTED_AT_RULES = ['media', 'supports', 'layer', 'container', 'scope'];

    /**
     * At-rules whose body is *not* a selector list. Scoping `0%` inside `@keyframes`
     * would silently break the animation, so these are passed through untouched.
     */
    private const OPAQUE_AT_RULES = [
        'keyframes', '-webkit-keyframes', '-moz-keyframes',
        'font-face', 'page', 'counter-style', 'property', 'viewport', 'font-feature-values',
    ];

    /**
     * Derive a short, stable scope id for a component.
     */
    public function scopeIdFor(string $view, string $css): string
    {
        return substr(hash('xxh128', $view . "\0" . $css), 0, 8);
    }

    /**
     * Scope every selector in a stylesheet.
     */
    public function scope(string $css, string $scopeId): string
    {
        if (trim($css) === '') {
            return $css;
        }

        $attribute = '[data-pwax-' . $scopeId . ']';
        $out = '';

        foreach ($this->parse($css) as $node) {
            $out .= match ($node['type']) {
                'statement' => $node['raw'],
                'at-rule' => $this->scopeAtRule($node, $attribute),
                default => $node['leading']
                    . $this->scopeSelectorList($node['prelude'], $attribute)
                    . '{' . $this->scope($node['body'], $scopeId) . '}',
            };
        }

        return $out;
    }

    /**
     * @param  array{type: string, leading: string, prelude: string, body: string, raw: string}  $node
     */
    private function scopeAtRule(array $node, string $attribute): string
    {
        $name = strtolower(ltrim(explode(' ', ltrim($node['prelude']), 2)[0], '@'));
        $name = rtrim($name, '(');

        if (in_array($name, self::OPAQUE_AT_RULES, true)) {
            return $node['raw'];
        }

        if (! in_array($name, self::NESTED_AT_RULES, true)) {
            return $node['raw'];
        }

        // Recurse with the already-built attribute rather than the raw scope id.
        $scopeId = substr($attribute, strlen('[data-pwax-'), -1);

        return $node['leading'] . $node['prelude'] . '{' . $this->scope($node['body'], $scopeId) . '}';
    }

    /**
     * Scope each selector of a comma-separated selector list.
     */
    private function scopeSelectorList(string $selectors, string $attribute): string
    {
        $scoped = array_map(
            fn (string $selector): string => $this->scopeSelector(trim($selector), $attribute),
            $this->splitTopLevel($selectors, ',')
        );

        return implode(',', array_filter($scoped, fn (string $s): bool => $s !== ''));
    }

    /**
     * Scope a single selector by qualifying its right-most compound selector.
     *
     * `.a .b` becomes `.a .b[data-pwax-x]` — the attribute goes on the element that is
     * actually matched, not on every ancestor in the chain.
     */
    private function scopeSelector(string $selector, string $attribute): string
    {
        if ($selector === '') {
            return '';
        }

        if (($global = $this->splitFunctionalPseudo($selector, ':global')) !== null) {
            return trim($global['before'] . ' ' . $global['inner'] . $global['after']);
        }

        if (($deep = $this->splitFunctionalPseudo($selector, ':deep')) !== null) {
            $before = trim($deep['before']);
            $head = $before === '' ? $attribute : $this->scopeSelector($before, $attribute);

            return trim($head . ' ' . trim($deep['inner']) . $deep['after']);
        }

        $compounds = $this->splitTopLevel($selector, ' ', ['>', '+', '~']);
        $last = array_pop($compounds);

        if ($last === null || trim($last) === '') {
            return $selector;
        }

        return rtrim(substr($selector, 0, strlen($selector) - strlen($last)))
            . ($compounds === [] ? '' : ' ')
            . $this->qualify(trim($last), $attribute);
    }

    /**
     * Insert the scope attribute into a compound selector, ahead of any pseudo-class or
     * pseudo-element so that `.a:hover` scopes as `.a[data-pwax-x]:hover`.
     */
    private function qualify(string $compound, string $attribute): string
    {
        $depth = 0;
        $length = strlen($compound);

        for ($i = 0; $i < $length; $i++) {
            $char = $compound[$i];

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ':' && $depth === 0) {
                return substr($compound, 0, $i) . $attribute . substr($compound, $i);
            }
        }

        return $compound . $attribute;
    }

    /**
     * Locate a functional pseudo like `:deep(...)` and split the selector around it.
     *
     * @return array{before: string, inner: string, after: string}|null
     */
    private function splitFunctionalPseudo(string $selector, string $pseudo): ?array
    {
        $needle = $pseudo . '(';
        $start = stripos($selector, $needle);

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($selector);

        for ($i = $start + strlen($needle) - 1; $i < $length; $i++) {
            if ($selector[$i] === '(') {
                $depth++;
            } elseif ($selector[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return [
                        'before' => substr($selector, 0, $start),
                        'inner' => substr($selector, $start + strlen($needle), $i - $start - strlen($needle)),
                        'after' => substr($selector, $i + 1),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Split a string on a delimiter, ignoring delimiters inside (), [], "" or ''.
     *
     * When `$alsoBreakOn` is given, those characters act as combinators: they are kept
     * with the piece that follows, so `.a > .b` splits into `.a` and `> .b`.
     *
     * @param  list<string>  $alsoBreakOn
     * @return list<string>
     */
    private function splitTopLevel(string $input, string $delimiter, array $alsoBreakOn = []): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === $quote && ($i === 0 || $input[$i - 1] !== '\\')) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;

                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }

            if ($depth === 0 && $char === $delimiter) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            if ($depth === 0 && in_array($char, $alsoBreakOn, true)) {
                if (trim($buffer) !== '') {
                    $parts[] = $buffer;
                }
                $buffer = $char;

                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '' || $parts === []) {
            $parts[] = $buffer;
        }

        return array_values(array_filter($parts, fn (string $p): bool => trim($p) !== ''));
    }

    /**
     * Split a stylesheet into top-level nodes, preserving the original text of each.
     *
     * @return list<array{type: string, leading: string, prelude: string, body: string, raw: string}>
     */
    private function parse(string $css): array
    {
        $nodes = [];
        $buffer = '';
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            $char = $css[$i];

            // Comments are opaque and carried through verbatim.
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $buffer .= substr($css, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $end = $this->skipString($css, $i);
                $buffer .= substr($css, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($char === '{') {
                $end = $this->matchBrace($css, $i);
                $body = substr($css, $i + 1, $end - $i - 1);
                $leading = $buffer === '' ? '' : (string) (preg_match('/^\s*/', $buffer, $m) ? $m[0] : '');
                $prelude = substr($buffer, strlen($leading));

                $nodes[] = [
                    'type' => str_starts_with(ltrim($prelude), '@') ? 'at-rule' : 'rule',
                    'leading' => $leading,
                    'prelude' => $prelude,
                    'body' => $body,
                    'raw' => $buffer . '{' . $body . '}',
                ];

                $buffer = '';
                $i = $end + 1;

                continue;
            }

            if ($char === ';' && str_starts_with(ltrim($buffer), '@')) {
                $nodes[] = [
                    'type' => 'statement',
                    'leading' => '',
                    'prelude' => $buffer,
                    'body' => '',
                    'raw' => $buffer . ';',
                ];
                $buffer = '';
                $i++;

                continue;
            }

            $buffer .= $char;
            $i++;
        }

        if (trim($buffer) !== '') {
            $nodes[] = ['type' => 'statement', 'leading' => '', 'prelude' => $buffer, 'body' => '', 'raw' => $buffer];
        }

        return $nodes;
    }

    /**
     * Return the offset just past the closing quote of the string starting at $start.
     */
    private function skipString(string $css, int $start): int
    {
        $quote = $css[$start];
        $length = strlen($css);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($css[$i] === '\\') {
                $i++;

                continue;
            }

            if ($css[$i] === $quote) {
                return $i + 1;
            }
        }

        return $length;
    }

    /**
     * Return the offset of the `}` matching the `{` at $start.
     */
    private function matchBrace(string $css, int $start): int
    {
        $depth = 0;
        $length = strlen($css);

        for ($i = $start; $i < $length; $i++) {
            $char = $css[$i];

            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = $this->skipString($css, $i) - 1;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $length - 1;
    }
}
