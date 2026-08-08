<?php

namespace Mxent\Pwax\Pwa;

/**
 * Glob patterns, compiled to regular expressions.
 *
 * The same patterns work in two places, which is why this is a compiler rather than a
 * matcher: the manifest builder uses them to decide which files under `public/` to list,
 * and the compiled expressions are written into `sw.json` so the service worker can decide
 * whether a URL belongs to a lazy asset group or a data group without shipping a glob
 * implementation of its own.
 *
 * The syntax is path-oriented rather than PHP's:
 *
 *     *          anything within one path segment
 *     **         anything, across segments
 *     ?          one character, not a separator
 *     {a,b}      either
 *     (a|b)      either — convenient for a list of file extensions
 *     !prefix    excludes; handled by the caller, since a rule list has to be read in order
 *
 * `fnmatch()` is not used. Its `**` handling varies by platform and `FNM_PATHNAME` is not
 * available everywhere, so a pattern that worked in development could quietly match
 * nothing in production.
 */
class Glob
{
    /**
     * Compile a glob to an anchored regular expression, without delimiters.
     *
     * Returned bare so it can be embedded in JSON and handed to JavaScript's `RegExp`,
     * whose syntax agrees with PCRE for everything produced here.
     */
    public static function toRegex(string $glob): string
    {
        $segments = explode('/', $glob);
        $last = count($segments) - 1;
        $regex = '';

        foreach ($segments as $index => $segment) {
            if ($segment === '**') {
                // Trailing `**` matches the rest of the path, including nothing at all.
                // In the middle it stands for whole segments, and the group is optional
                // so that `/a/**/b` also matches `/a/b` — which is what someone writing
                // it means.
                $regex .= $index === $last ? '.*' : '(?:.+/)?';

                continue;
            }

            $regex .= self::segment($segment);

            if ($index !== $last) {
                $regex .= '/';
            }
        }

        return '^' . $regex . '$';
    }

    /**
     * Compile one path segment, where `*` and `?` stop at a separator.
     */
    private static function segment(string $segment): string
    {
        $out = '';
        $length = strlen($segment);

        for ($i = 0; $i < $length; $i++) {
            $char = $segment[$i];

            if ($char === '*') {
                // `**` crosses separators wherever it appears, not only as a whole
                // segment. Both `/**/*.css` and `/**.css` are spellings people reach for,
                // and one of them silently matching nothing is a poor way to find out
                // which this implementation preferred.
                if (($segment[$i + 1] ?? '') === '*') {
                    $out .= '.*';
                    $i++;

                    continue;
                }

                $out .= '[^/]*';

                continue;
            }

            if ($char === '?') {
                $out .= '[^/]';

                continue;
            }

            if ($char === '{' || $char === '(') {
                $alternation = self::alternation($segment, $i, $char === '{' ? '}' : ')');

                if ($alternation !== null) {
                    [$compiled, $i] = $alternation;
                    $out .= $compiled;

                    continue;
                }
            }

            $out .= preg_quote($char, '#');
        }

        return $out;
    }

    /**
     * Compile `{a,b}` or `(a|b)` starting at `$start`, or null if it is never closed.
     *
     * An unclosed brace is treated as a literal brace rather than an error: a pattern is
     * configuration, and refusing to build the whole manifest over one is a poor trade
     * against matching one file fewer than intended.
     *
     * @return array{string, int}|null The compiled group and the index of the closer.
     */
    private static function alternation(string $segment, int $start, string $closer): ?array
    {
        $end = strpos($segment, $closer, $start);

        if ($end === false) {
            return null;
        }

        $inner = substr($segment, $start + 1, $end - $start - 1);

        $split = preg_split('/[,|]/', $inner);

        if ($split === false) {
            return null;
        }

        $parts = [];

        foreach ($split as $part) {
            $parts[] = self::segment($part);
        }

        return ['(?:' . implode('|', $parts) . ')', $end];
    }

    /**
     * Does a path match this glob?
     */
    public static function matches(string $glob, string $path): bool
    {
        return preg_match('#' . self::toRegex($glob) . '#', $path) === 1;
    }

    /**
     * Read a rule list in order, with `!` excluding.
     *
     * A path has to be claimed by at least one positive rule and refused by none. With no
     * rules at all everything matches, which keeps an empty configuration meaning "no
     * opinion" rather than "nothing".
     *
     * @param  list<string>  $rules
     */
    public static function matchesAny(array $rules, string $path): bool
    {
        if ($rules === []) {
            return true;
        }

        $included = false;

        foreach ($rules as $rule) {
            $negated = str_starts_with($rule, '!');
            $glob = $negated ? substr($rule, 1) : $rule;

            if (! self::matches($glob, $path)) {
                continue;
            }

            if ($negated) {
                return false;
            }

            $included = true;
        }

        return $included;
    }

    /**
     * Compile a rule list for the worker, keeping the `!` prefix it reads itself.
     *
     * @param  list<string>  $rules
     * @return list<string>
     */
    public static function compile(array $rules): array
    {
        $compiled = [];

        foreach ($rules as $rule) {
            $compiled[] = str_starts_with($rule, '!')
                ? '!' . self::toRegex(substr($rule, 1))
                : self::toRegex($rule);
        }

        return $compiled;
    }
}
