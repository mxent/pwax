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
 * The old spellings still work, and will for this major cycle — `pwax:doctor` names them.
 * Normalising here rather than at each call site means the manifest only ever carries the
 * new vocabulary, so the service worker knows one set of words.
 */
final class Strategy
{
    public const NETWORK_ONLY = 'network-only';

    public const NETWORK_FIRST = 'network-first';

    public const CACHE_FIRST = 'cache-first';

    public const STALE_WHILE_REVALIDATE = 'stale-while-revalidate';

    /**
     * What each retired spelling meant.
     *
     * `app-shell` belonged to navigations and `freshness`/`performance` to pages and data
     * groups, but all three described behaviour the four names above already cover.
     */
    public const ALIASES = [
        'freshness' => self::NETWORK_FIRST,
        'performance' => self::CACHE_FIRST,
        'app-shell' => self::CACHE_FIRST,
    ];

    /**
     * Resolve a configured value, falling back when it is not one we recognise.
     *
     * @param  list<string>  $allowed
     */
    public static function resolve(mixed $value, array $allowed, string $fallback): string
    {
        $name = strtolower(trim((string) $value));
        $name = self::ALIASES[$name] ?? $name;

        return in_array($name, $allowed, true) ? $name : $fallback;
    }

    /**
     * Is this one of the spellings we still accept but no longer document?
     */
    public static function isDeprecated(mixed $value): bool
    {
        return is_string($value) && isset(self::ALIASES[strtolower(trim($value))]);
    }
}
