<?php

namespace Mxent\Pwax\Pwa;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Log;
use Mxent\Pwax\Pwax;
use Mxent\Pwax\Support\Shell;
use Throwable;

/**
 * Builds `sw.json`, the asset manifest that drives the service worker.
 *
 * The server enumerates every URL the application is made of, hashes each one, and hands
 * the browser a table. The worker installs the whole application from that table in one
 * pass, so a visitor who has loaded exactly one page can go offline and still reach every
 * route, every component and every asset. Nothing has to be visited first to be
 * available.
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
 * between deploys. Nothing request-specific may reach it — a manifest carrying a CSRF
 * token or a user id would give every visitor a private cache name and a full
 * re-download of the application.
 *
 * @phpstan-type PwaxAssetGroup array{
 *     name: string,
 *     installMode: 'prefetch'|'lazy',
 *     updateMode: 'prefetch'|'lazy',
 *     kind: 'asset'|'page',
 *     urls: list<string>,
 *     patterns: list<string>,
 *     strategy?: string,
 *     timeout?: int,
 *     credentials?: string,
 *     maxEntries?: int,
 * }
 */
class AssetManifest
{
    private const CACHE_KEY = 'pwax:asset-manifest';

    /**
     * The headers the client runtime sends when it asks for a page payload.
     *
     * These are exactly the fields named in {@see Pwax::VARY}, and they have to stay that
     * way. The worker fetches pages with them and stores the entry under a request that
     * carries them, so the Cache API's `Vary` check against the runtime's own request
     * succeeds. When the two sides disagreed, every page lookup missed — silently, and
     * for a reason nothing in either codebase made visible.
     *
     * @var array<string, string>
     */
    private const PAGE_HEADERS = [
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Pwax-Component' => 'true',
    ];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly Config $config,
        private readonly Pwax $pwax,
        private readonly Shell $shell,
        private readonly WebManifest $webManifest,
        private readonly ComponentRegistry $registry,
        private readonly PageRegistry $pages,
        private readonly Application $app,
        private readonly ViewFactory $views,
        private readonly PublicAssets $assets,
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

        $this->warnings = [];

        $groups = array_merge(
            [$this->group('app', $this->appGroup($hashes, $crossOrigin, $critical))],
            [$this->group('components', $this->componentGroup($hashes))],
            $this->configuredGroups($hashes),
            [$this->pageGroup($hashes)],
        );

        $groups = array_values(array_filter(
            $groups,
            static fn (array $group): bool => $group['urls'] !== [] || $group['patterns'] !== []
        ));

        ksort($hashes);

        $crossOrigin = array_values(array_unique($crossOrigin));
        $critical = array_values(array_unique($critical));

        sort($crossOrigin);
        sort($critical);

        $manifest = [
            'configVersion' => 2,
            'version' => (string) $this->config->get('pwax.service_worker.version', 'v1'),
            'cachePrefix' => (string) $this->config->get('pwax.service_worker.cache_name', 'pwax'),
            'strategy' => (string) $this->config->get('pwax.service_worker.strategy', 'network-first'),
            'maxEntries' => (int) $this->config->get('pwax.service_worker.max_entries', 60),
            'navigationPreload' => (bool) $this->config->get('pwax.service_worker.navigation_preload', true),
            'navigationStrategy' => $this->navigationStrategy(),
            'navigationUrls' => Glob::compile($this->navigationUrls()),
            'shellUrl' => $this->shellUrl(),
            'offlineUrl' => $this->offlineUrl(),
            'assetPrefixes' => $this->assetPrefixes(),
            // The exact header set the client runtime sends for a page. The worker fetches
            // pages with these and keys its cache on them, so a page response's `Vary` can
            // be satisfied on lookup. A test asserts this agrees with `Pwax::VARY`.
            'pageHeaders' => self::PAGE_HEADERS,
            'pageRuntime' => (bool) $this->config->get('pwax.service_worker.pages.runtime', true),
            'pageDefaults' => $this->pageDefaults(),
            'identityCacheLimit' => (int) $this->config->get('pwax.service_worker.identity_cache_limit', 2),
            'assetGroups' => $groups,
            'dataGroups' => $this->dataGroups(),
            'hashTable' => $hashes,
            'crossOrigin' => $crossOrigin,
            'critical' => $critical,
            'warnings' => $this->warnings,
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

        $urls[] = $this->manifestPath();

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

        // Configured stylesheets. These were rendered into every page's head and left out
        // of the manifest, so an application with `pwax.styles` set went offline unstyled
        // — which looks far more broken than being offline does.
        foreach ($this->shell->stylesheets() as $tag) {
            $href = $tag['href'] ?? null;

            if (is_string($href) && $href !== '') {
                $urls[] = $href;

                if ($this->isCrossOrigin($href)) {
                    $crossOrigin[] = $href;
                }
            }
        }

        $favicon = $this->webManifest->favicon();

        if ($favicon !== null) {
            $urls[] = $favicon;
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
     * Wrap a list of URLs as a plain prefetched asset group.
     *
     * @param  list<string>  $urls
     * @return PwaxAssetGroup
     */
    private function group(string $name, array $urls): array
    {
        return [
            'name' => $name,
            'installMode' => 'prefetch',
            'updateMode' => 'prefetch',
            'kind' => 'asset',
            'urls' => $urls,
            'patterns' => [],
        ];
    }

    /**
     * The groups declared in `service_worker.asset_groups`, with their globs resolved.
     *
     * This is where images, fonts, stylesheets and build output enter the manifest. The
     * compiled patterns travel to the worker as well as the resolved URLs, because a lazy
     * group has to be recognised at request time for a file that was never fetched at
     * install and so has no entry in the URL list.
     *
     * @param  array<string, string>  $hashes
     * @return list<PwaxAssetGroup>
     */
    private function configuredGroups(array &$hashes): array
    {
        $groups = [];

        /** @var list<mixed> $declared */
        $declared = (array) $this->config->get('pwax.service_worker.asset_groups', []);

        foreach ($declared as $index => $declaration) {
            if (! is_array($declaration)) {
                continue;
            }

            $name = is_string($declaration['name'] ?? null) ? $declaration['name'] : 'group-' . $index;

            /** @var list<string> $patterns */
            $patterns = array_values(array_filter((array) ($declaration['files'] ?? []), 'is_string'));

            /** @var list<string> $urls */
            $urls = array_values(array_filter((array) ($declaration['urls'] ?? []), 'is_string'));

            foreach ($this->assets->match($patterns) as $file) {
                $urls[] = $file['url'];
                $hashes[$file['url']] ??= $file['hash'];
            }

            if ($this->assets->truncated()) {
                $this->warnings[] = sprintf(
                    'Asset group "%s" hit service_worker.max_files or max_bytes and was truncated. '
                        . 'Narrow its globs, or raise the ceiling if the whole of public/ really '
                        . 'should be precached.',
                    $name
                );
            }

            $lazy = ($declaration['install_mode'] ?? 'prefetch') === 'lazy';

            $groups[] = [
                'name' => $name,
                'installMode' => $lazy ? 'lazy' : 'prefetch',
                'updateMode' => ($declaration['update_mode'] ?? 'prefetch') === 'lazy' ? 'lazy' : 'prefetch',
                'kind' => 'asset',
                'urls' => $this->register(array_values(array_unique($urls)), $hashes),
                'patterns' => Glob::compile($patterns),
            ];
        }

        return $groups;
    }

    /**
     * Application routes to make available offline, fetched as payloads rather than pages.
     *
     * @param  array<string, string>  $hashes
     * @return PwaxAssetGroup
     */
    private function pageGroup(array &$hashes): array
    {
        $urls = [];

        /** @var list<mixed> $configured */
        $configured = (array) $this->config->get('pwax.service_worker.pages.urls', []);

        foreach ($configured as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        // Every route that renders a page, so offline is not limited to wherever the
        // visitor happened to go first. Installing from the home page and then opening
        // Settings offline used to fail on the one page nobody had opened yet.
        foreach ($this->pages->precachable() as $page) {
            $urls[] = $page['url'];
        }

        $defaults = $this->pageDefaults();

        return [
            'name' => 'pages',
            'installMode' => 'prefetch',
            'updateMode' => 'prefetch',
            'kind' => 'page',
            // Deliberately not registered in the hash table: a page is rendered, not a
            // file, so there is nothing on disk to digest. It is re-fetched on each new
            // manifest, which is what you want for something whose content the server
            // decides.
            'urls' => array_values(array_unique($urls)),
            'patterns' => [],
            'strategy' => $defaults['strategy'],
            'timeout' => $defaults['timeout'],
            'credentials' => $defaults['credentials'],
            'maxEntries' => $defaults['maxEntries'],
        ];
    }

    /**
     * How a page payload is fetched and how long the worker waits for it.
     *
     * @return array{strategy: string, timeout: int, credentials: string, maxEntries: int}
     */
    private function pageDefaults(): array
    {
        $strategy = (string) $this->config->get('pwax.service_worker.pages.strategy', 'freshness');

        return [
            'strategy' => $strategy === 'performance' ? 'performance' : 'freshness',
            'timeout' => (int) $this->config->get('pwax.service_worker.pages.timeout', 2000),
            // Anonymous by default. A page is precacheable because it renders the same for
            // everyone, so the guest rendering is the correct one to store; fetching with
            // cookies would precache whichever visitor happened to trigger the install.
            'credentials' => (string) $this->config->get('pwax.service_worker.pages.credentials', 'omit'),
            'maxEntries' => (int) $this->config->get('pwax.service_worker.pages.max_entries', 60),
        ];
    }

    /**
     * Runtime caching rules for API responses.
     *
     * @return list<array<string, mixed>>
     */
    private function dataGroups(): array
    {
        $groups = [];

        /** @var list<mixed> $declared */
        $declared = (array) $this->config->get('pwax.service_worker.data_groups', []);

        foreach ($declared as $index => $declaration) {
            if (! is_array($declaration)) {
                continue;
            }

            /** @var list<string> $patterns */
            $patterns = array_values(array_filter((array) ($declaration['urls'] ?? []), 'is_string'));

            if ($patterns === []) {
                continue;
            }

            /** @var array<string, mixed> $cache */
            $cache = (array) ($declaration['cache_config'] ?? []);

            $strategy = (string) ($cache['strategy'] ?? 'freshness');

            $groups[] = [
                'name' => is_string($declaration['name'] ?? null) ? $declaration['name'] : 'data-' . $index,
                'version' => (int) ($declaration['version'] ?? 1),
                'patterns' => Glob::compile($patterns),
                'cacheConfig' => [
                    'strategy' => $strategy === 'performance' ? 'performance' : 'freshness',
                    'maxSize' => (int) ($cache['max_size'] ?? 50),
                    'maxAge' => (int) ($cache['max_age'] ?? 3600),
                    'timeout' => (int) ($cache['timeout'] ?? 3000),
                ],
            ];
        }

        return $groups;
    }

    private function navigationStrategy(): string
    {
        $strategy = (string) $this->config->get('pwax.service_worker.navigation_strategy', 'network-first');

        return $strategy === 'app-shell' ? 'app-shell' : 'network-first';
    }

    /**
     * Which navigations belong to the application.
     *
     * @return list<string>
     */
    private function navigationUrls(): array
    {
        /** @var list<string> $rules */
        $rules = array_values(array_filter(
            (array) $this->config->get('pwax.service_worker.navigation_urls', []),
            'is_string'
        ));

        return $rules;
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

        if ($url === $this->manifestPath()) {
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
     * Hashed from the rendered HTML, because that is the thing being cached. The previous
     * version hashed a list of configuration values instead — and `$this->pwax->shell()`
     * returns the shell's *view name*, a constant string, not its content. So editing
     * `layouts/shell.blade.php`, either `includes/` partial, any `pwax.blade.*` override
     * or the loader and error templates left the hash untouched, and the worker copied the
     * stale shell forward from the previous cache on every deploy.
     *
     * Rendered through a request-free Shell. With the ambient request it would pick up the
     * visitor's CSRF token and identity, giving every signed-in user a different manifest
     * hash, a private cache name, and a re-download of the entire application.
     */
    private function shellHash(): string
    {
        try {
            $html = $this->views->make($this->pwax->shell(), [
                'pwaxInitial' => null,
                'pwaxComponent' => null,
                'pwaxShell' => $this->shell->withoutRequest(),
            ])->render();

            $version = (string) $this->config->get('pwax.service_worker.version', 'v1');

            return substr(hash('xxh128', $html . "\0" . $version), 0, 16);
        } catch (Throwable $e) {
            Log::warning(
                'pwax: could not render the shell to hash it; falling back to a configuration digest. '
                    . 'The offline shell may not bust its cache entry when its markup changes.',
                ['exception' => $e]
            );

            return $this->configurationHash();
        }
    }

    /**
     * The pre-3.0 shell digest, kept as the fallback when rendering is not possible.
     *
     * Blind to the shell's own markup, which is why it is no longer the primary — but it
     * still changes when the things most likely to change do, and a coarse hash is a far
     * better failure mode than a manifest build that throws and takes `sw.json` with it.
     */
    private function configurationHash(): string
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

    /**
     * Where the Web App Manifest is served from.
     *
     * Read in one place because it is compared against as well as listed, and the two
     * used to carry different hardcoded defaults — so changing the path left the entry
     * unhashed and re-downloaded on every deploy.
     */
    private function manifestPath(): string
    {
        return (string) $this->config->get('pwax.manifest_path', '/manifest.json');
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
        $prefixes = [];

        foreach ([
            (string) $this->config->get('pwax.route_prefix', '__pwax__'),
            (string) $this->config->get('pwax.assets.local_path', '/vendor/pwax'),
        ] as $prefix) {
            $prefix = trim($prefix, '/');

            // Skipped rather than emitted as "/". A prefix of "/" would claim every path
            // on the origin as a Pwax asset and route the whole site through the
            // stale-while-revalidate branch.
            if ($prefix !== '') {
                $prefixes[] = '/' . $prefix . '/';
            }
        }

        return array_values(array_unique($prefixes));
    }

    private function isCrossOrigin(string $url): bool
    {
        return is_string(parse_url($url, PHP_URL_HOST));
    }

    private function publicPath(string $path): string
    {
        return rtrim((string) $this->app->publicPath(), '/\\')
            . DIRECTORY_SEPARATOR
            . ltrim($path, '/\\');
    }
}
