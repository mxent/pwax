<?php

namespace Mxent\Pwax\Compiler;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Log;
use Mxent\Pwax\Contracts\Minifier;
use Mxent\Pwax\Data\Component;
use Mxent\Pwax\Support\ComponentId;
use Throwable;

/**
 * Renders a Blade view and splits it into a {@see Component}.
 *
 * The compile cache is keyed on a digest of the *rendered output*, not on the view name
 * or file mtime. That distinction matters: a view-name key goes stale whenever an
 * `@include`d partial changes, and an mtime key goes stale whenever anything is
 * generated at runtime. Keying on what was actually rendered can never be stale — a
 * changed component simply produces a new key — while still skipping the parse, scope
 * and minify work for the overwhelmingly common case of unchanged output.
 *
 * That same property is why not every render may be *written*. A page compiled with
 * controller data renders differently for every visitor, so keying on the output mints a
 * fresh entry per request that nothing will ever read again — permanent growth driven by
 * traffic, on a store with no way to purge these selectively. Callers say whether a
 * render is worth storing; `$store` is false for those, and they still read the cache,
 * because a hit is free and — the key being the output — always correct.
 */
class ComponentCompiler
{
    private const CACHE_PREFIX = 'pwax:component:';

    private static bool $cacheFailureReported = false;

    /**
     * How many compiled components to keep in memory.
     *
     * Bounded rather than per-request, and deliberately so. `Pwax` is a singleton holding
     * this compiler, so under Octane or FrankenPHP an unbounded memo would outlive every
     * request and grow for the life of the worker — the same unbounded growth this class
     * now refuses to write to the cache store. A request compiles a handful of distinct
     * components; anything beyond this is a process that has been up a long time.
     */
    private const MEMO_LIMIT = 64;

    /**
     * Components already compiled in this process, newest last.
     *
     * The store is reached once per distinct output rather than once per call. Two
     * `@pwaxImport`s of the same component, or a caller that reads `toArray()` before
     * returning the response, would otherwise each pay a round trip to Redis for an
     * answer already in hand. Correct to hold across requests for the same reason the
     * cache is: the key is the rendered output, so a hit can never be the wrong answer.
     *
     * @var array<string, Component>
     */
    private array $memo = [];

    public function __construct(
        private readonly ViewFactory $views,
        private readonly BlockExtractor $extractor,
        private readonly StyleScoper $scoper,
        private readonly TemplateStamper $stamper,
        private readonly Minifier $minifier,
        private readonly ?Repository $cache = null,
        private readonly bool $scopedStyles = true,
        private readonly ?int $ttl = null,
    ) {}

    /**
     * Render and compile a Blade view into its component parts.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $store  Whether the compiled result is worth keeping. See the class docblock.
     */
    public function compile(string $view, array $data = [], bool $store = true): Component
    {
        ComponentId::validate($view);

        return $this->compileSource($view, $this->views->make($view, $data)->render(), $store);
    }

    /**
     * Compile already-rendered Blade output. Useful for tests and for callers that have
     * their own rendering pipeline.
     */
    public function compileSource(string $view, string $contents, bool $store = true): Component
    {
        if ($this->cache === null) {
            return $this->parse($view, $contents);
        }

        // The scoping flag is part of the key: toggling it changes the output for
        // identical input, and a cache that ignored it would serve the wrong one.
        $key = self::CACHE_PREFIX . hash('xxh128', $view . "\0" . ($this->scopedStyles ? '1' : '0') . "\0" . $contents);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        // Caching is an optimisation and is never allowed to be the reason a page fails.
        // Laravel defaults `CACHE_STORE` to `database`, so an application that has not
        // run the cache migration would otherwise see every component 500 with a
        // "no such table: cache" QueryException.
        try {
            $cached = $this->cache->get($key);

            if ($cached instanceof Component) {
                return $this->remember($key, $cached);
            }
        } catch (Throwable $e) {
            $this->reportCacheFailure($e);

            return $this->parse($view, $contents);
        }

        $component = $this->remember($key, $this->parse($view, $contents));

        if (! $store) {
            return $component;
        }

        try {
            $this->ttl === null
                ? $this->cache->forever($key, $component)
                : $this->cache->put($key, $component, $this->ttl);
        } catch (Throwable $e) {
            $this->reportCacheFailure($e);
        }

        return $component;
    }

    /**
     * Hold a compiled component in memory, evicting the oldest once the memo is full.
     */
    private function remember(string $key, Component $component): Component
    {
        if (count($this->memo) >= self::MEMO_LIMIT) {
            array_shift($this->memo);
        }

        return $this->memo[$key] = $component;
    }

    /**
     * Warn once per process. A broken cache store would otherwise log on every component
     * of every request, which buries the one line that explains the problem.
     */
    private function reportCacheFailure(Throwable $e): void
    {
        if (self::$cacheFailureReported) {
            return;
        }

        self::$cacheFailureReported = true;

        if (function_exists('app') && app()->bound('log')) {
            Log::warning(
                'pwax: the component cache is unavailable, compiling on every request instead. '
                . 'Check pwax.cache.store, or set pwax.cache.components to false.',
                ['error' => $e->getMessage()]
            );
        }
    }

    private function parse(string $view, string $contents): Component
    {
        $template = $this->extractor->template($contents);

        $script = $this->joinScripts($this->extractor->scripts($contents));

        [$style, $scopeId, $template] = $this->buildStyles($view, $contents, $template);

        return new Component(
            view: $view,
            template: trim($template),
            script: $script === '' ? '' : $this->minifier->js($script),
            style: $style === '' ? '' : $this->minifier->css($style),
            externalStyles: $this->extractor->externalStyles($contents),
            externalScripts: $this->extractor->externalScripts($contents),
            scopeId: $scopeId,
        );
    }

    /**
     * Combine the component's `<style>` blocks, scoping the ones marked `scoped` and
     * stamping the template to match.
     *
     * @return array{0: string, 1: string|null, 2: string}
     */
    private function buildStyles(string $view, string $contents, string $template): array
    {
        $blocks = $this->extractor->styles($contents);

        if ($blocks === []) {
            return ['', null, $template];
        }

        $scopedSources = [];
        $globalSources = [];

        foreach ($blocks as $block) {
            // With scoping disabled, a `scoped` block still has to be served — just
            // globally, exactly as it behaved before the feature existed.
            if ($this->scopedStyles && isset($block['attributes']['scoped'])) {
                $scopedSources[] = $block['content'];
            } else {
                $globalSources[] = $block['content'];
            }
        }

        if ($scopedSources === []) {
            return [implode("\n", $globalSources), null, $template];
        }

        $scopedCss = implode("\n", $scopedSources);
        $scopeId = $this->scoper->scopeIdFor($view, $scopedCss);

        $css = array_merge($globalSources, [$this->scoper->scope($scopedCss, $scopeId)]);

        return [implode("\n", $css), $scopeId, $this->stamper->stamp($template, $scopeId)];
    }

    /**
     * Join multiple `<script>` blocks into one module.
     *
     * Blocks are separated by a newline and a semicolon so that a block ending in an
     * expression cannot be silently glued onto the next one by ASI.
     *
     * @param  list<array{content: string, attributes: array<string, string|true>}>  $blocks
     */
    private function joinScripts(array $blocks): string
    {
        $sources = array_map(static fn (array $block): string => trim($block['content']), $blocks);

        return implode("\n;\n", array_filter($sources, static fn (string $s): bool => $s !== ''));
    }
}
