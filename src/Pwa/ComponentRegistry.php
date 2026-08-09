<?php

namespace Mxent\Pwax\Pwa;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\View\FileViewFinder;
use Mxent\Pwax\Exceptions\InvalidComponentId;
use Mxent\Pwax\Support\ComponentId;

/**
 * Finds every Blade view in the application that is a Pwax component.
 *
 * This is what makes offline-first possible at all. The `@pwax` directive resolves at
 * request time, so nothing in the application declares up front which components exist —
 * which means a service worker has nothing to precache and can only cache a component
 * *after* the user has already loaded it online. Walking the view paths gives the asset
 * manifest a complete list instead, so the whole application can be installed in one go.
 *
 * Discovery is by content, not by directory convention: a file is a component if it has a
 * `<template>` block, or a `<script>` block that exports something. That covers pages,
 * imported components, and the script-only views used for plugins, directives and client
 * middleware, without assuming anyone names their folders the way the README does.
 */
class ComponentRegistry
{
    private const EXTENSION = '.blade.php';

    /**
     * Views are read to decide whether they are components. This caps how much of each
     * file is examined: the blocks that identify a component are in the first few
     * kilobytes of any realistic view, and a 2 MB generated template should not be read
     * in full just to be rejected.
     */
    private const SNIFF_BYTES = 65536;

    /**
     * The last walk's result, held until someone says it may be out of date.
     *
     * The walk is not cheap — every view root, every `.blade.php` sniffed for a component
     * block, `hash_file()` over each match — and it was being repeated within a single
     * answer. `AssetManifest::build()` asks once for the components group, and
     * `PageRegistry` asks again because it scopes routes by the same selection; then
     * `pwax:doctor` and `pwax:precache` ask a third and fourth time for their summary
     * lines. Four full walks to produce one manifest, over a view tree that cannot have
     * changed in between.
     *
     * Deliberately not a per-request or per-instance lifetime. This is a singleton, and a
     * memo that outlived a build would be wrong in the one situation that matters: adding
     * a component and rebuilding must find it. `AssetManifest::build()` calls `forget()`
     * before it starts, so the answer is exactly as old as the build using it.
     *
     * @var list<array{view: string, path: string, hash: string}>|null
     */
    private ?array $components = null;

    public function __construct(
        private readonly ViewFactory $views,
        private readonly Config $config,
        private readonly Filesystem $files,
    ) {}

    /**
     * Every component the application contains, whether or not it is precached.
     *
     * @return list<array{view: string, path: string, hash: string}>
     */
    public function all(): array
    {
        if ($this->components !== null) {
            return $this->components;
        }

        $found = [];

        foreach ($this->searchPaths() as $namespace => $roots) {
            foreach ($roots as $root) {
                foreach ($this->componentsIn($root, is_string($namespace) ? $namespace : null) as $view => $path) {
                    // The view finder resolves in registration order, so the first path
                    // to define a name is the one that would actually be rendered.
                    $found[$view] ??= $path;
                }
            }
        }

        ksort($found);

        $components = [];

        foreach ($found as $view => $path) {
            $hash = @hash_file('xxh128', $path);

            if ($hash === false) {
                continue;
            }

            $components[] = ['view' => $view, 'path' => $path, 'hash' => substr($hash, 0, 16)];
        }

        return $this->components = $components;
    }

    /**
     * Discard the remembered walk.
     *
     * Called at the start of a manifest build, which is what bounds how stale the answer
     * may be. Also worth calling from a long-lived process that has just written a view.
     */
    public function forget(): void
    {
        $this->components = null;
    }

    /**
     * The components selected for precaching by `pwax.service_worker.components`.
     *
     * @return list<array{view: string, path: string, hash: string}>
     */
    public function precachable(): array
    {
        $selection = $this->config->get('pwax.service_worker.components', 'all');

        if ($selection === false || $selection === null || $selection === 'none' || $selection === []) {
            return [];
        }

        /** @var list<string> $exclude */
        $exclude = array_values(array_filter(
            (array) $this->config->get('pwax.service_worker.exclude', []),
            'is_string'
        ));

        /** @var list<string> $allowed */
        $allowed = array_values(array_filter(
            (array) $this->config->get('pwax.components.allowed', []),
            'is_string'
        ));

        $patterns = $selection === 'all' || $selection === true
            ? null
            : array_values(array_filter((array) $selection, 'is_string'));

        return array_values(array_filter(
            $this->all(),
            static function (array $component) use ($patterns, $exclude, $allowed): bool {
                $view = $component['view'];

                if ($exclude !== [] && Str::is($exclude, $view)) {
                    return false;
                }

                // A view the application refuses to serve must not appear in a manifest
                // that tells the browser to go and fetch it.
                if ($allowed !== [] && ! Str::is($allowed, $view)) {
                    return false;
                }

                return $patterns === null || Str::is($patterns, $view);
            }
        ));
    }

    /**
     * Directories to scan, keyed by view namespace (or an integer for the default one).
     *
     * @return array<int|string, list<string>>
     */
    private function searchPaths(): array
    {
        $finder = $this->views->getFinder();

        /** @var list<string> $roots */
        $roots = $finder instanceof FileViewFinder ? array_values($finder->getPaths()) : [];

        foreach ((array) $this->config->get('pwax.service_worker.paths', []) as $extra) {
            if (is_string($extra) && $extra !== '') {
                $roots[] = $extra;
            }
        }

        $paths = [array_values(array_unique($roots))];

        // Namespaced views belong to packages, not to the application, and every package
        // that calls `loadViewsFrom()` registers one — Laravel's own exception page
        // among them. Scanning those found `laravel-exceptions-renderer::components.query`
        // and put it in the manifest, which is both useless offline and a signed URL for
        // a view nobody meant to expose. Opt in per namespace instead.
        /** @var list<string> $namespaces */
        $namespaces = array_values(array_filter(
            (array) $this->config->get('pwax.service_worker.namespaces', []),
            'is_string'
        ));

        if ($namespaces === [] || ! $finder instanceof FileViewFinder) {
            return $paths;
        }

        $hints = $finder->getHints();

        foreach ($namespaces as $namespace) {
            // The package's own views are shell fragments and the worker source, never
            // components an application would import.
            if ($namespace === 'pwax' || ! isset($hints[$namespace])) {
                continue;
            }

            $paths[$namespace] = array_values(array_filter($hints[$namespace], 'is_string'));
        }

        return $paths;
    }

    /**
     * Map every component-looking Blade file under a root to its view name.
     *
     * @return array<string, string>
     */
    private function componentsIn(string $root, ?string $namespace): array
    {
        if (! $this->files->isDirectory($root)) {
            return [];
        }

        $root = rtrim((string) (realpath($root) ?: $root), DIRECTORY_SEPARATOR);

        $components = [];

        foreach ($this->files->allFiles($root) as $file) {
            if (! str_ends_with($file->getFilename(), self::EXTENSION)) {
                continue;
            }

            $view = $this->viewName($root, $file->getPathname(), $namespace);

            if ($view === null || ! $this->looksLikeComponent($file->getPathname())) {
                continue;
            }

            $components[$view] = $file->getPathname();
        }

        return $components;
    }

    /**
     * Turn an absolute file path into the dotted view name Blade would resolve it by.
     */
    private function viewName(string $root, string $path, ?string $namespace): ?string
    {
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR . '/');
        $relative = substr($relative, 0, -strlen(self::EXTENSION));

        $view = str_replace(['\\', '/'], '.', $relative);

        if ($namespace !== null && $namespace !== '') {
            $view = $namespace . '::' . $view;
        }

        // A name Pwax cannot sign is a name it cannot serve, so it has no business in
        // the manifest. This is also the gate that keeps odd filenames out of URLs.
        try {
            ComponentId::validate($view);
        } catch (InvalidComponentId) {
            return null;
        }

        return $view;
    }

    /**
     * Decide whether a Blade file is a Pwax component rather than an ordinary view.
     *
     * A component has a `<template>` block, or a `<script>` block that exports — the
     * latter being how plugins, directives and client middleware are written.
     */
    private function looksLikeComponent(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $contents = (string) fread($handle, self::SNIFF_BYTES);
        fclose($handle);

        if (stripos($contents, '<template') !== false) {
            return true;
        }

        if (stripos($contents, '<script') === false) {
            return false;
        }

        return preg_match('/\bexport\s+(?:default|function|const|let|var|class|\{)/', $contents) === 1;
    }
}
