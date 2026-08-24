<?php

namespace Mxent\Pwax\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Templates that have already been compiled to Vue render functions.
 *
 * Written by `php artisan pwax:compile` and read on every request that serves a component,
 * so the read has to be free. It is a plain PHP array literal `require`d once: OPcache
 * holds the parsed form, which makes a lookup a hash-table hit with no I/O after the first
 * request of a worker's life.
 *
 * In `storage/app`, not the cache store — it must survive `cache:clear`, and it should be
 * buildable in CI and shipped as a deploy artifact. Not `bootstrap/cache` either: that
 * belongs to Laravel, and `optimize:clear` would delete it.
 *
 * Keyed on the *stamped* template — the one with the scope attribute already applied — so
 * a lookup composes with the compile cache rather than introducing a second thing that can
 * be stale. A template that changes produces a new key, and a key with no entry simply
 * means "not precompiled", which every caller already handles.
 */
class RenderFunctionStore
{
    /** @var array<string, string>|null */
    private ?array $functions = null;

    /** @var array<string, mixed> */
    private array $meta = [];

    public function __construct(
        private readonly Config $config,
        private readonly Filesystem $files,
    ) {}

    public function path(): string
    {
        $configured = $this->config->get('pwax.assets.render_functions');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return storage_path('app/pwax/render-functions.php');
    }

    /**
     * The compiled render function for a stamped template, or null.
     */
    public function get(string $template): ?string
    {
        $functions = $this->load();

        return $functions[self::key($template)] ?? null;
    }

    /**
     * The module source that exposes a template's render function, or null.
     *
     * Emitted as JavaScript rather than as a string to be compiled, which is the whole
     * point: a component module is evaluated by the module loader, so no `Function`
     * constructor is involved and `script-src 'unsafe-eval'` is not required.
     *
     * The compiler emits a chunk ending in `return function render(…)`, so evaluating it
     * inside an arrow function yields the function itself. `export` is hoisted, so both
     * lines can sit together at the top of the module regardless of what follows.
     */
    public function bindings(string $template): ?string
    {
        $render = $this->get($template);

        if ($render === null) {
            return null;
        }

        return "const __pwaxRender = (() => {\n" . $render . "\n})();\nexport { __pwaxRender };";
    }

    /**
     * The key a template is stored under.
     */
    public static function key(string $template): string
    {
        return substr(hash('xxh128', $template), 0, 16);
    }

    /**
     * Has the application asked for the runtime-only Vue build?
     *
     * Config alone — whether the store can actually deliver on it is {@see active()}.
     */
    public function wanted(): bool
    {
        return strtolower(trim((string) $this->config->get('pwax.assets.vue_build', 'full'))) === 'runtime';
    }

    /**
     * Is precompiling switched on, and is there anything to show for it?
     *
     * One cached boolean, not a per-component check. `Shell::frameworkScripts()` consults
     * this on every page render to decide which Vue build to serve, and a store lookup on
     * that path would be a poor trade for the bytes it saves.
     */
    public function isComplete(): bool
    {
        return $this->load() !== [];
    }

    /**
     * Is the runtime-only build actually being served?
     *
     * Deliberately not a freshness check. Proving the store covers every component means
     * walking the view tree, and this is consulted on every page render; `pwax:doctor` is
     * where drift is detected, and it walks the tree once, on purpose.
     */
    public function active(): bool
    {
        return $this->wanted() && $this->isComplete();
    }

    /**
     * How many templates are stored.
     */
    public function count(): int
    {
        return count($this->load());
    }

    /**
     * What `pwax:compile` recorded about the run that produced this store.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $this->load();

        return $this->meta;
    }

    /**
     * The views the last compile covered, mapped to the key their template is stored under.
     *
     * Read by `pwax:doctor` to name what a stale store is missing. A view listed here whose
     * key is absent from the store means the template changed since the compile — which is
     * exactly the state that breaks a runtime-only build.
     *
     * @return array<string, string>
     */
    public function views(): array
    {
        /** @var mixed $views */
        $views = $this->meta()['views'] ?? [];

        return is_array($views) ? self::strings($views) : [];
    }

    /**
     * @param  array<string, string>  $functions  key => compiled source
     * @param  array<string, mixed>  $meta
     */
    public function write(array $functions, array $meta = []): void
    {
        $path = $this->path();

        $this->files->ensureDirectoryExists(dirname($path));

        $this->files->put($path, $this->export($functions, $meta));

        // The file is `require`d once per process and OPcache holds the parsed form, so a
        // rewrite that does not invalidate leaves the old array in memory until the worker
        // restarts — which is how a deploy ships new templates and old render functions.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        $this->functions = $functions;
        $this->meta = $meta;
    }

    public function flush(): void
    {
        $path = $this->path();

        if ($this->files->isFile($path)) {
            $this->files->delete($path);
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        $this->functions = [];
        $this->meta = [];
    }

    /**
     * Forget what was read from disk. Test seam, and useful in a long-lived worker.
     */
    public function forget(): void
    {
        $this->functions = null;
        $this->meta = [];
    }

    /**
     * @param  array<string, string>  $functions
     * @param  array<string, mixed>  $meta
     */
    private function export(array $functions, array $meta): string
    {
        $lines = [
            "<?php\n",
            "\n",
            "// Generated by `php artisan pwax:compile`. Do not edit, and do not commit\n",
            "// unless your deploy ships it as a build artifact.\n",
            "\n",
            'return ' . var_export(['meta' => $meta, 'functions' => $functions], true) . ";\n",
        ];

        return implode('', $lines);
    }

    /**
     * @return array<string, string>
     */
    private function load(): array
    {
        if ($this->functions !== null) {
            return $this->functions;
        }

        $path = $this->path();

        if (! $this->files->isFile($path)) {
            return $this->functions = [];
        }

        try {
            /** @var mixed $loaded */
            $loaded = require $path;
        } catch (Throwable) {
            // A half-written file — a deploy interrupted mid-copy — must not take the
            // application down. Without render functions every component falls back to
            // being compiled in the browser, which is the default path anyway.
            return $this->functions = [];
        }

        if (! is_array($loaded)) {
            return $this->functions = [];
        }

        /** @var array<string, mixed> $meta */
        $meta = is_array($loaded['meta'] ?? null) ? $loaded['meta'] : [];
        $this->meta = $meta;

        /** @var mixed $functions */
        $functions = $loaded['functions'] ?? [];

        return $this->functions = is_array($functions) ? self::strings($functions) : [];
    }

    /**
     * The string-keyed, string-valued entries of an array read from disk.
     *
     * The file is generated, so in practice everything in it is already the right shape.
     * It is also a file on disk that a deploy can truncate and a person can edit, and this
     * is the boundary where that stops being someone else's problem.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $strings[(string) $key] = $value;
            }
        }

        return $strings;
    }
}
