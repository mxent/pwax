<?php

namespace Mxent\Pwax\Minification;

use Illuminate\Contracts\Cache\Repository;
use Mxent\Pwax\Contracts\Minifier;

/**
 * Memoises another minifier, keyed on a digest of the source.
 *
 * Minification is pure — the same input always yields the same output — but it is also
 * the most expensive thing Pwax does per request, and without this it would run on every
 * component fetch. Caching on content means a component pays for minification once, and
 * cache entries for edited components fall out naturally because the digest changes.
 */
class CachedMinifier implements Minifier
{
    private const PREFIX = 'pwax:min:';

    public function __construct(
        private readonly Minifier $inner,
        private readonly Repository $cache,
        private readonly ?int $ttl = null,
    ) {}

    public function js(string $source): string
    {
        return $this->remember('js', $source, fn (): string => $this->inner->js($source));
    }

    public function css(string $source): string
    {
        return $this->remember('css', $source, fn (): string => $this->inner->css($source));
    }

    /**
     * @param  \Closure(): string  $callback
     */
    private function remember(string $language, string $source, \Closure $callback): string
    {
        if (trim($source) === '') {
            return '';
        }

        $key = self::PREFIX . $language . ':' . hash('xxh128', $source);

        // A cache that is unreachable or misconfigured must cost performance, never
        // correctness — minification still runs, it just is not memoised.
        try {
            return $this->ttl === null
                ? $this->cache->rememberForever($key, $callback)
                : $this->cache->remember($key, $this->ttl, $callback);
        } catch (\Throwable) {
            return $callback();
        }
    }
}
