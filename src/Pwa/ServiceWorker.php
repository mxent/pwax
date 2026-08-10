<?php

namespace Mxent\Pwax\Pwa;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\Filesystem;
use Mxent\Pwax\Support\Shell;
use RuntimeException;

/**
 * Assembles the JavaScript served at `/sw.js`.
 *
 * Three pieces, concatenated: a header comment naming the build, a preamble carrying
 * everything the server decided, and the prebuilt worker from `dist/pwax-sw.js`. Anything
 * in `service_worker.extend` follows, sharing the worker's scope.
 *
 * Concatenated rather than pulled in with `importScripts`. That would cost a synchronous
 * blocking request during install, and a stale HTTP-cached bundle could pair a new preamble
 * with an old worker — one byte stream cannot come apart that way.
 *
 * The manifest hash is in the preamble and in the header comment, which is what makes a
 * deploy reach an existing install: a browser treats a worker as new only if its bytes
 * differ, so a worker whose source never changed would leave clients on the build they
 * first installed.
 */
class ServiceWorker
{
    private const CACHE_KEY = 'pwax:sw:bundle';

    public function __construct(
        private readonly Config $config,
        private readonly ViewFactory $views,
        private readonly Shell $shell,
        private readonly Filesystem $files,
        private readonly ?CacheRepository $cache = null,
    ) {}

    /**
     * The complete worker for a manifest.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function build(array $manifest): string
    {
        // A published Blade worker still wins, and always will. Someone who forked the
        // 4.0 view to add a handler should not have it silently replaced by an upgrade.
        $blade = $this->config->get('pwax.service_worker.blade');

        if (is_string($blade) && $blade !== '') {
            return $this->views->make($blade, ['manifest' => $manifest])->render();
        }

        return implode("\n", array_filter([
            $this->header($manifest),
            'self.__PWAX_SW__ = ' . $this->preamble($manifest) . ';',
            $this->bundle(),
            $this->extensions(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function header(array $manifest): string
    {
        return sprintf(
            "/*!\n * pwax service worker\n * manifest: %s\n * version:  %s\n */",
            (string) ($manifest['hash'] ?? 'unknown'),
            (string) ($manifest['version'] ?? 'v1'),
        );
    }

    /**
     * Everything the worker needs to answer a request without a network round trip.
     *
     * Deliberately not the whole manifest: the asset table belongs in `sw.json`, which is
     * fetched at install and stored alongside the cache it built. What is inlined is only
     * what the fetch handler consults — which cache to look in, and what to serve when a
     * navigation fails — so a worker the browser revived after terminating it can respond
     * straight away.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function preamble(array $manifest): string
    {
        $payload = [
            'manifestUrl' => '/' . ltrim((string) $this->config->get('pwax.service_worker.asset_manifest.path', '/sw.json'), '/'),
            'manifestHash' => (string) ($manifest['hash'] ?? ''),
            'prefix' => (string) ($manifest['cachePrefix'] ?? $this->config->get('pwax.service_worker.cache_name', 'pwax')),
            'config' => [
                'hash' => (string) ($manifest['hash'] ?? ''),
                'strategy' => (string) ($manifest['strategy'] ?? Strategy::NETWORK_FIRST),
                'maxEntries' => (int) ($manifest['maxEntries'] ?? 60),
                'maxEntryBytes' => (int) ($manifest['maxEntryBytes'] ?? 0),
                'navigationPreload' => (bool) ($manifest['navigationPreload'] ?? true),
                'navigationStrategy' => (string) ($manifest['navigationStrategy'] ?? Strategy::NETWORK_FIRST),
                'navigationUrls' => array_values((array) ($manifest['navigationUrls'] ?? [])),
                'shellUrl' => $manifest['shellUrl'] ?? null,
                'offlineUrl' => $manifest['offlineUrl'] ?? null,
                'assetPrefixes' => array_values((array) ($manifest['assetPrefixes'] ?? [])),
                'pageHeaders' => (array) ($manifest['pageHeaders'] ?? []),
                'crossOrigin' => array_values((array) ($manifest['crossOrigin'] ?? [])),
                'concurrency' => (int) $this->config->get('pwax.service_worker.concurrency', 6),
                'offlineHtml' => $this->offlineHtml(),
                // Fallbacks for a push whose payload omits them. A browser that receives
                // a push and shows nothing eventually loses the origin's permission.
                'push' => array_filter([
                    'title' => $this->config->get('pwax.push.title'),
                    'icon' => $this->config->get('pwax.push.icon'),
                    'badge' => $this->config->get('pwax.push.badge'),
                ], static fn (mixed $v): bool => is_string($v) && $v !== ''),
            ],
        ];

        // `JSON_HEX_TAG` and nothing more. This is a standalone JavaScript file served as
        // `application/javascript`, not JSON inlined into a document, so escaping quotes
        // and ampersands only inflates it — but `</` still gets escaped, because an
        // application that does inline this somewhere should not be able to break out.
        return (string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );
    }

    /**
     * The document a navigation gets when there is no network and nothing stored.
     *
     * A view rather than a string in the worker, so it picks up the application's language
     * and direction and can be published and edited on its own.
     */
    private function offlineHtml(): string
    {
        $view = (string) ($this->config->get('pwax.service_worker.offline_view') ?: 'pwax::js.offline');

        return trim($this->views->make($view, [
            'lang' => str_replace('_', '-', (string) $this->config->get('app.locale', 'en')),
            'dir' => $this->shell->direction(),
            'title' => 'Offline',
            'heading' => 'This page is not available offline',
            'message' => 'It has not been stored on this device. Reconnect and try again.',
        ])->render());
    }

    /**
     * The prebuilt worker, read once and memoised on its own digest.
     */
    private function bundle(): string
    {
        $path = $this->path();

        if (! $this->files->isFile($path)) {
            throw new RuntimeException(
                'pwax: dist/pwax-sw.js is missing. Run `npm run build` or reinstall the package.'
            );
        }

        $key = self::CACHE_KEY . ':' . (string) $this->files->lastModified($path);

        if ($this->cache === null) {
            return $this->files->get($path);
        }

        try {
            return (string) $this->cache->remember($key, 3600, fn (): string => $this->files->get($path));
        } catch (\Throwable) {
            // Reading a file is not worth failing a request over a broken cache store.
            return $this->files->get($path);
        }
    }

    public function path(): string
    {
        return dirname(__DIR__, 2) . '/dist/pwax-sw.js';
    }

    /**
     * Application scripts appended after the worker.
     *
     * The supported way to add a `push` handler, a `sync` handler, or anything else the
     * package does not ship — instead of forking 1,600 lines to add ten. Each entry is a
     * view name or an absolute path; they run in the worker's own scope, so `CONFIG`,
     * `PREFIX` and the cache helpers are all in reach.
     */
    private function extensions(): string
    {
        $sources = [];

        foreach ((array) $this->config->get('pwax.service_worker.extend', []) as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }

            $sources[] = $this->views->exists($entry)
                ? $this->views->make($entry)->render()
                : ($this->files->isFile($entry) ? $this->files->get($entry) : sprintf(
                    '/* pwax: service_worker.extend entry %s is neither a view nor a readable file */',
                    json_encode($entry, JSON_THROW_ON_ERROR)
                ));
        }

        return implode("\n", $sources);
    }
}
