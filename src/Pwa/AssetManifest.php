<?php

namespace Mxent\Pwax\Pwa;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Mxent\Pwax\Pwax;
use Mxent\Pwax\Support\Shell;
use Throwable;

/**
 * Builds `sw.json`, the asset manifest that drives the service worker.
 *
 * This is the same idea as Angular's `ngsw.json`: the server enumerates every URL the
 * application is made of, hashes each one, and hands the browser a table. The worker
 * installs the whole application from that table in one pass, so a visitor who has loaded
 * exactly one page can go offline and still reach every route, every component and every
 * asset. Nothing has to be visited first to be available.
 *
 * The hashes are the cache-busting mechanism. Each entry is content-addressed, so a
 * deploy that changed one component re-downloads one file and copies the rest from the
 * previous cache; and the digest of the whole table names the cache, so a change anywhere
 * produces a new cache and an old client can never mix versions. Bumping
 * `service_worker.version` still works, but it is no longer how you ship a change — it is
 * only there for the case where you want to discard everything deliberately.
 *
 * The manifest body is deterministic: identical configuration and identical files produce
 * a byte-identical document, which is what lets it be served with an ETag and diffed
 * between deploys.
 */
class AssetManifest
{
    private const CACHE_KEY = 'pwax:asset-manifest';

    public function __construct(
        private readonly Config $config,
        private readonly Pwax $pwax,
        private readonly Shell $shell,
        private readonly WebManifest $webManifest,
        private readonly ComponentRegistry $registry,
        private readonly Application $app,
        private readonly ?CacheRepository $cache = null,
    ) {}

    /**
     * The manifest, memoised for `service_worker.asset_manifest.ttl` seconds.
     *
     * Building it walks the view directory. That is cheap but not free, and the worker
     * only reads it on install and on each update check, so paying for a directory scan
     * on every request would be a poor trade.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $ttl = (int) $this->config->get('pwax.service_worker.asset_manifest.ttl', 60);

        if ($this->cache === null || $ttl <= 0) {
            return $this->build();
        }

        try {
            $cached = $this->cache->get(self::CACHE_KEY);

            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return $cached;
            }
        } catch (Throwable) {
            // A missing or misconfigured cache store must never be the reason the app
            // cannot install itself. Fall through and build.
            return $this->build();
        }

        $manifest = $this->build();

        try {
            $this->cache->put(self::CACHE_KEY, $manifest, $ttl);
        } catch (Throwable) {
            // Same again: the manifest is already built and correct.
        }

        return $manifest;
    }

    /**
     * Discard the memoised manifest.
     */
    public function flush(): void
    {
        try {
            $this->cache?->forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Nothing to flush if the store is unreachable.
        }
    }

    /**
     * Build the manifest from scratch.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $hashes = [];
        $crossOrigin = [];
        $critical = [];

        $app = $this->appGroup($hashes, $crossOrigin, $critical);
        $routes = $this->routeGroup($hashes);
        $components = $this->componentGroup($hashes);

        $groups = [];

        foreach ([
            ['name' => 'app', 'installMode' => 'prefetch', 'urls' => $app],
            ['name' => 'routes', 'installMode' => 'prefetch', 'urls' => $routes],
            ['name' => 'components', 'installMode' => 'prefetch', 'urls' => $components],
        ] as $group) {
            if ($group['urls'] !== []) {
                $groups[] = $group;
            }
        }

        ksort($hashes);

        $crossOrigin = array_values(array_unique($crossOrigin));
        $critical = array_values(array_unique($critical));

        sort($crossOrigin);
        sort($critical);

        $manifest = [
            'version' => (string) $this->config->get('pwax.service_worker.version', 'v1'),
            'cachePrefix' => (string) $this->config->get('pwax.service_worker.cache_name', 'pwax'),
            'strategy' => (string) $this->config->get('pwax.service_worker.strategy', 'network-first'),
            'maxEntries' => (int) $this->config->get('pwax.service_worker.max_entries', 60),
            'navigationPreload' => (bool) $this->config->get('pwax.service_worker.navigation_preload', true),
            'shellUrl' => $this->shellUrl(),
            'offlineUrl' => $this->offlineUrl(),
            'assetPrefixes' => $this->assetPrefixes(),
            'assetGroups' => $groups,
            'hashTable' => $hashes,
            'crossOrigin' => array_values($crossOrigin),
            'critical' => array_values($critical),
        ];

        // Computed last, over everything above, so any change anywhere renames the cache.
        $manifest['hash'] = substr(hash('xxh128', (string) json_encode(
            $manifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )), 0, 16);

        return $manifest;
    }

    public function toJson(): string
    {
        return json_encode(
            $this->get(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    /**
     * The URL `sw.json` is served from.
     */
    public function path(): string
    {
        return '/' . ltrim((string) $this->config->get(
            'pwax.service_worker.asset_manifest.path',
            '/sw.json'
        ), '/');
    }

    /**
     * The framework, the runtime, the web manifest and the offline shell.
     *
     * These are what "the application" means before any of its own content: without them
     * cached, a cold start offline shows nothing at all, however many components are
     * stored alongside.
     *
     * @param  array<string, string>  $hashes
     * @param  list<string>  $crossOrigin
     * @param  list<string>  $critical
     * @return list<string>
     */
    private function appGroup(array &$hashes, array &$crossOrigin, array &$critical): array
    {
        if (! $this->config->get('pwax.service_worker.assets', true)) {
            return $this->shellOnlyGroup($hashes, $critical);
        }

        $urls = [];

        foreach ($this->shell->frameworkScripts() as $tag) {
            $src = $tag['src'] ?? null;

            if (! is_string($src) || $src === '') {
                continue;
            }

            $urls[] = $src;

            if ($this->isCrossOrigin($src)) {
                // A CDN build can still be precached — jsDelivr and unpkg both send CORS
                // headers — but it is never critical: an install must not fail because a
                // third party is having a bad day. `pwax:doctor` says the rest.
                $crossOrigin[] = $src;

                continue;
            }

            $critical[] = $src;
        }

        $urls[] = $this->shell->runtimeUrl();
        $critical[] = $this->shell->runtimeUrl();

        $urls[] = (string) $this->config->get('pwax.manifest_path', '/manifest.webmanifest');

        // The application's own extra scripts. Precached, never critical: whether an
        // analytics tag is reachable is not a reason to leave the app uninstalled.
        foreach ($this->shell->vendorScripts() as $tag) {
            $src = $tag['src'] ?? null;

            if (is_string($src) && $src !== '') {
                $urls[] = $src;

                if ($this->isCrossOrigin($src)) {
                    $crossOrigin[] = $src;
                }
            }
        }

        foreach ((array) $this->config->get('pwax.service_worker.files', []) as $file) {
            if (is_string($file) && $file !== '') {
                $urls[] = $file;
            }
        }

        $urls = array_merge($urls, $this->shellOnlyGroup($hashes, $critical));

        return $this->register(array_values(array_unique($urls)), $hashes);
    }

    /**
     * The offline shell and the configured offline page.
     *
     * @param  array<string, string>  $hashes
     * @param  list<string>  $critical
     * @return list<string>
     */
    private function shellOnlyGroup(array &$hashes, array &$critical): array
    {
        $urls = [];

        $shell = $this->shellUrl();

        if ($shell !== null) {
            $urls[] = $shell;

            // Without the shell there is no offline navigation at all, so an install that
            // could not fetch it is worse than no install: it would replace a worker that
            // was working with one that answers every offline navigation with an error.
            $critical[] = $shell;
        }

        $offline = $this->offlineUrl();

        if ($offline !== null && $offline !== $shell) {
            $urls[] = $offline;
        }

        return $this->register($urls, $hashes);
    }

    /**
     * Application routes the developer asked to precache.
     *
     * @param  array<string, string>  $hashes
     * @return list<string>
     */
    private function routeGroup(array &$hashes): array
    {
        $urls = [];

        foreach ((array) $this->config->get('pwax.service_worker.precache', []) as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $this->register(array_values(array_unique($urls)), $hashes);
    }

    /**
     * Every selected component, addressed by its signed module URL.
     *
     * @param  array<string, string>  $hashes
     * @return list<string>
     */
    private function componentGroup(array &$hashes): array
    {
        $urls = [];

        foreach ($this->registry->precachable() as $component) {
            try {
                $url = $this->pwax->url($component['view']);
            } catch (Throwable) {
                continue;
            }

            $urls[] = $url;

            // Hashed from the view file rather than from the compiled response: hashing
            // the response would mean rendering every component to build the manifest,
            // and a component that renders differently per user would then produce a
            // different manifest per user. The file digest changes whenever the author
            // changes the component, which is what the cache needs to know.
            $hashes[$url] = $component['hash'];
        }

        return array_values(array_unique($urls));
    }

    /**
     * Record a content hash for each URL that maps to a file on disk.
     *
     * A URL with no hash is still precached; it simply cannot take part in the
     * copy-from-the-previous-cache optimisation and is re-fetched whenever the manifest
     * changes. That is the right default for a rendered route, whose content is not a
     * file at all.
     *
     * @param  list<string>  $urls
     * @param  array<string, string>  $hashes
     * @return list<string>
     */
    private function register(array $urls, array &$hashes): array
    {
        foreach ($urls as $url) {
            if (isset($hashes[$url])) {
                continue;
            }

            $hash = $this->hashFor($url);

            if ($hash !== null) {
                $hashes[$url] = $hash;
            }
        }

        return $urls;
    }

    private function hashFor(string $url): ?string
    {
        if ($this->isCrossOrigin($url)) {
            return null;
        }

        if ($url === $this->pwax->route('pwax.runtime')) {
            return $this->hashFile(dirname(__DIR__, 2) . '/dist/pwax.js');
        }

        if ($url === (string) $this->config->get('pwax.manifest_path', '/manifest.webmanifest')) {
            return $this->webManifest->hash();
        }

        if ($url === $this->shellUrl()) {
            return $this->shellHash();
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        return $this->hashFile($this->publicPath(rawurldecode($path)));
    }

    /**
     * A digest for the offline shell.
     *
     * The shell is rendered, not stored, so there is no file to hash — and rendering it
     * here to hash the output would be wasted work on every manifest build. Everything
     * that can change it is configuration or the runtime bundle, so hashing those gives
     * an entry that busts exactly when it should.
     */
    private function shellHash(): string
    {
        $inputs = [
            $this->pwax->shell(),
            (string) $this->config->get('pwax.service_worker.version', 'v1'),
            (string) json_encode($this->config->get('pwax.customization', [])),
            (string) json_encode($this->config->get('pwax.blade', [])),
            (string) json_encode($this->config->get('pwax.styles', [])),
            (string) json_encode($this->config->get('pwax.scripts', [])),
            (string) json_encode($this->config->get('pwax.plugins', [])),
            (string) json_encode($this->config->get('pwax.directives', [])),
            (string) json_encode($this->config->get('pwax.middleware_js', [])),
            $this->webManifest->hash(),
            (string) $this->hashFile(dirname(__DIR__, 2) . '/dist/pwax.js'),
        ];

        return substr(hash('xxh128', implode("\0", $inputs)), 0, 16);
    }

    private function hashFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $hash = @hash_file('xxh128', $path);

        return $hash === false ? null : substr($hash, 0, 16);
    }

    private function shellUrl(): ?string
    {
        if (! $this->config->get('pwax.service_worker.shell.enabled', true)) {
            return null;
        }

        return '/' . ltrim((string) $this->config->get(
            'pwax.service_worker.shell.path',
            '/__pwax__/shell'
        ), '/');
    }

    private function offlineUrl(): ?string
    {
        $url = $this->config->get('pwax.service_worker.offline_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * URL prefixes whose contents are Pwax's own and are always safe to cache.
     *
     * @return list<string>
     */
    private function assetPrefixes(): array
    {
        return array_values(array_unique(array_filter([
            '/' . trim((string) $this->config->get('pwax.route_prefix', '__pwax__'), '/') . '/',
            '/' . trim((string) $this->config->get('pwax.assets.local_path', '/vendor/pwax'), '/') . '/',
        ])));
    }

    private function isCrossOrigin(string $url): bool
    {
        return is_string(parse_url($url, PHP_URL_HOST));
    }

    private function publicPath(string $path): string
    {
        $base = method_exists($this->app, 'publicPath')
            ? (string) $this->app->publicPath()
            : $this->app->basePath('public');

        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
