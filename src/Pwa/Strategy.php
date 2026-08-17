<?php

namespace Mxent\Pwax\Pwa;

/**
 * One vocabulary for "when do we go to the network?".
 *
 * Four config keys used to answer that question in three different languages.
 * `runtime_strategy` said `network-first`, `pages.strategy` said `freshness` for the same
 * behaviour, `navigation_strategy` said `app-shell` where the others said `cache-first`,
 * and `assets.strategy` — the worst of them — meant `local` or `cdn` and had nothing to do
 * with caching at all. Reading a Pwax config meant holding three glossaries at once.
 *
 * The names here are the ones the rest of the web uses, so they can be looked up:
 *
 *   network-only            never stored, always fetched
 *   network-first           fetch, fall back to what is stored
 *   cache-first             serve what is stored, fetch only when there is nothing
 *   stale-while-revalidate  serve what is stored and refresh it in the background
 *
 * These four are now the only spellings. `freshness`, `performance` and `app-shell` were
 * accepted as aliases through the 4.x cycle and are gone: an unrecognised value falls back
 * to the caller's default, and `pwax:doctor` reports it. Normalising here rather than at
 * each call site means the manifest only ever carries this vocabulary, so the service
 * worker knows one set of words.
 */
final class Strategy
{
    public const NETWORK_ONLY = 'network-only';

    public const NETWORK_FIRST = 'network-first';

    public const CACHE_FIRST = 'cache-first';

    public const STALE_WHILE_REVALIDATE = 'stale-while-revalidate';

    /**
     * Resolve a configured value, falling back when it is not one we recognise.
     *
     * @param  list<string>  $allowed
     */
    public static function resolve(mixed $value, array $allowed, string $fallback): string
    {
        $name = strtolower(trim((string) $value));

        return in_array($name, $allowed, true) ? $name : $fallback;
    }

    /**
     * Is this a strategy name at all?
     *
     * A configured value that is not one falls back silently at the point of use — which is
     * right for serving a page and wrong for telling someone their config is not doing what
     * they wrote. `pwax:doctor` asks this so a typo, or a spelling retired in 5.0, is named
     * rather than quietly ignored.
     *
     * Null and blank mean "not set", which is not a mistake — every strategy key has a
     * default. Anything that is not a string is a mistake and is reported as one without
     * being cast: `(string) []` is a PHP warning, and a doctor that emits one while
     * explaining a config error has made the output harder to read, not easier.
     */
    public static function isUnknown(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (! is_string($value)) {
            return true;
        }

        $name = strtolower(trim($value));

        if ($name === '') {
            return false;
        }

        return ! in_array($name, [
            self::NETWORK_ONLY,
            self::NETWORK_FIRST,
            self::CACHE_FIRST,
            self::STALE_WHILE_REVALIDATE,
        ], true);
    }
}
