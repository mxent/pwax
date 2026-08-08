<?php

namespace Mxent\Pwax\Pwa;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use SplFileInfo;
use Throwable;

/**
 * Finds the files under `public/` that belong in the asset manifest.
 *
 * Before this, "offline" meant the framework, the components and the shell — everything
 * Pwax generates — and nothing the application actually looks like. A logo, a webfont, a
 * compiled stylesheet or a Vite bundle had to be listed one URL at a time, and anything
 * missed was a blank space or an unstyled page for whoever went offline.
 *
 * The pattern language is Angular's, so `ngsw-config.json` translates without a manual;
 * see {@see Glob}. Matching happens against one directory walk, memoised, because a
 * manifest typically has several groups and walking `public/` once per group on a site
 * with a large media directory is the difference between a fast build and a slow one.
 */
class PublicAssets
{
    /**
     * Never served as part of an application, whatever the globs say.
     *
     * A precache entry is a URL the browser is told to go and fetch, so a careless
     * `/**` must not be able to turn `.env.backup`, a stray `.php` file or an editor
     * swap file into one. Source maps are excluded separately, by config: they are
     * legitimate, merely large and useless without devtools open.
     *
     * @var list<string>
     */
    private const DENY = [
        '/**.php',
        '/**.htaccess',
        '/**.htpasswd',
        '/**/web.config',
        '/**/.*',
        '/.*',
        '/hot',
        '/**/mix-manifest.json',
        // `public/storage` is a symlink to user uploads. Walking it would precache
        // whatever anyone has ever uploaded, which is both enormous and not ours.
        '/storage/**',
    ];

    /** @var list<array{url: string, path: string, bytes: int}>|null */
    private ?array $files = null;

    private bool $truncated = false;

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * Every public file matching these rules, sorted, hashed, and capped.
     *
     * @param  list<string>  $patterns
     * @return list<array{url: string, hash: string, bytes: int}>
     */
    public function match(array $patterns): array
    {
        if ($patterns === []) {
            return [];
        }

        $matched = [];

        foreach ($this->all() as $file) {
            if (Glob::matchesAny($patterns, $file['url'])) {
                $matched[] = $file;
            }
        }

        return $this->cap($matched);
    }

    /**
     * Did the last `match()` have to stop early?
     */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Apply the size and count ceilings.
     *
     * Truncation rather than an exception, and at the sorted boundary rather than
     * wherever the walk happened to be. A runaway `/**` on a media-heavy `public/`
     * should degrade to a partial precache with a message someone can act on — not a
     * 500 on `sw.json`, which would take the application's whole offline capability
     * down with it. Cutting at a stable point keeps the manifest deterministic, which
     * is what stops the cache renaming itself on every request.
     *
     * @param  list<array{url: string, path: string, bytes: int}>  $files
     * @return list<array{url: string, hash: string, bytes: int}>
     */
    private function cap(array $files): array
    {
        $maxFiles = (int) $this->config->get('pwax.service_worker.max_files', 2000);
        $maxBytes = (int) $this->config->get('pwax.service_worker.max_bytes', 67108864);

        $kept = [];
        $bytes = 0;

        $this->truncated = false;

        foreach ($files as $file) {
            if ($maxFiles > 0 && count($kept) >= $maxFiles) {
                $this->truncated = true;
                break;
            }

            if ($maxBytes > 0 && $bytes + $file['bytes'] > $maxBytes) {
                $this->truncated = true;
                break;
            }

            $hash = $this->hash($file['path']);

            if ($hash === null) {
                continue;
            }

            $bytes += $file['bytes'];
            $kept[] = ['url' => $file['url'], 'hash' => $hash, 'bytes' => $file['bytes']];
        }

        return $kept;
    }

    /**
     * Every candidate file under `public/`, walked once and remembered.
     *
     * @return list<array{url: string, path: string, bytes: int}>
     */
    private function all(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $root = rtrim((string) $this->app->publicPath(), '/\\');

        if ($root === '' || ! $this->filesystem->isDirectory($root)) {
            return $this->files = [];
        }

        $found = [];

        try {
            // `allFiles()` does not follow symlinks, which is what keeps `public/storage`
            // — a link to user uploads — out of the manifest.
            $files = $this->filesystem->allFiles($root);
        } catch (Throwable) {
            return $this->files = [];
        }

        foreach ($files as $file) {
            $url = $this->urlFor($root, $file);

            if ($url === null || Glob::matchesAny($this->deny(), $url)) {
                continue;
            }

            $found[$url] = [
                'url' => $url,
                'path' => $file->getPathname(),
                'bytes' => max(0, (int) $file->getSize()),
            ];
        }

        // Sorted by URL so the manifest is byte-identical between builds. Filesystem
        // iteration order is not guaranteed, and a manifest that differed run to run
        // would rename its cache on every request and re-download the application.
        ksort($found);

        return $this->files = array_values($found);
    }

    /**
     * The deny list, plus source maps unless the application asked for them.
     *
     * @return list<string>
     */
    private function deny(): array
    {
        $deny = self::DENY;

        if (! $this->config->get('pwax.service_worker.source_maps', false)) {
            $deny[] = '/**.map';
        }

        /** @var list<string> $configured */
        $configured = array_values(array_filter(
            (array) $this->config->get('pwax.service_worker.exclude_files', []),
            'is_string'
        ));

        return array_merge($deny, $configured);
    }

    /**
     * The URL a public file is served from, or null if it escapes the root.
     */
    private function urlFor(string $root, SplFileInfo $file): ?string
    {
        $path = $file->getPathname();

        if (! str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $relative = substr($path, strlen($root));

        return '/' . ltrim(str_replace('\\', '/', $relative), '/');
    }

    private function hash(string $path): ?string
    {
        $hash = @hash_file('xxh128', $path);

        return $hash === false ? null : substr($hash, 0, 16);
    }
}
