<?php

namespace Mxent\Pwax\Pwa\Ssr;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mxent\Pwax\Data\Component;
use Mxent\Pwax\Http\Responses\ComponentResponse;
use Mxent\Pwax\Pwax;
use Mxent\Pwax\Support\Shell;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Prerenders a page component to HTML through the Node SSR bridge.
 *
 * The bridge (`bin/ssr.mjs`) receives the same {@see Component} the browser would
 * receive — template, script, style, scope — plus the controller data the route passed
 * to `pwaxRender()`, and returns the rendered HTML alongside the resolved component
 * state. This service decides *whether* to prerender, spawns Node, validates the result,
 * caches it, and falls back to the SPA shell on any failure.
 *
 * Per-visitor pages are excluded by default. A page rendered with controller data is
 * only prerendered when it has declared itself visitor-independent through
 * {@see ComponentResponse::cacheable()} — the same claim the compile cache and the
 * service worker's offline cache already rely on. A page with no data is always
 * eligible. This mirrors the boundary `Pwax::payload()` draws for addressability, for
 * the same reason: a page whose output depends on the visitor cannot be prerendered for
 * everyone.
 *
 * Caching is keyed on the component hash plus a digest of the controller data, so a
 * changed component or a changed payload produces a new entry. A page rendered with no
 * data is cached indefinitely (like the compile cache); a page rendered with data is
 * cached only when it is cacheable, and for the TTL `ssr.cache.ttl` or the response
 * declared.
 *
 * There is no `flush()` here on purpose. `pwax:clear` flushes the store named by
 * `pwax.ssr.cache.store` along with the others, which is the same thing and does not need
 * this class to be resolved to do it.
 */
class Prerenderer
{
    private const CACHE_PREFIX = 'pwax:ssr:';

    /**
     * A ceiling on how many components one prerender will gather.
     *
     * Not a limit anyone should meet: it is the backstop for a component graph that is
     * larger than anybody intended, so a single request cannot compile the whole
     * application while a visitor waits.
     */
    private const MAX_IMPORTS = 100;

    /**
     * A per-request memo, so a response that reads its own prerender twice pays once.
     *
     * @var array<string, array{html: string, state: string, styles: array<string, string>}>
     */
    private array $memo = [];

    private bool $failureReported = false;

    public function __construct(
        private readonly Config $config,
        private readonly ?CacheRepository $cache = null,
    ) {}

    /**
     * Should this response be prerendered?
     *
     * The decision combines the global switch, the route pattern match, the response's
     * own opt-out, and the visitor-independence claim. A route that passes data but did
     * not call {@see ComponentResponse::cacheable()} is left to the SPA, because its
     * prerendered output would be particular to one visitor.
     */
    public function shouldRender(ComponentResponse $response, Request $request): bool
    {
        if (! (bool) $this->config->get('pwax.ssr.enabled', false)) {
            return false;
        }

        $view = $response->view();

        if ($response->isSpaOnly()) {
            return $this->skip($view, 'the route called spaOnly()');
        }

        if (! $this->matchesRoutes($view)) {
            return $this->skip($view, 'the view name does not match pwax.ssr.routes');
        }

        if ($this->isExcluded($view)) {
            return $this->skip($view, 'the view name matches pwax.ssr.exclude');
        }

        // An explicit `prerenderable()` is the route author saying this page is safe to
        // prerender for everyone, which is exactly the claim the check below infers. It
        // therefore overrides that check rather than merely surviving it — without this,
        // the method could only ever be a no-op, since not opting out is the default.
        if ($response->isPrerenderForced()) {
            return true;
        }

        // A page rendered with no data is a pure function of its view name: every visitor
        // produces the same bytes, so it is safe to prerender. A page rendered *with*
        // data is only safe when the route has declared it visitor-independent through
        // `cacheable()` — the same claim the compile cache relies on.
        if ($response->data() === [] || $response->isCacheable()) {
            return true;
        }

        return $this->skip(
            $view,
            'it was rendered with controller data and declared neither cacheable() nor prerenderable()'
        );
    }

    /**
     * Record why a route was not prerendered, and answer "no".
     *
     * Silence is the wrong answer here. A route that quietly serves the SPA shell looks
     * exactly like SSR not working at all, and the eligibility rules — a view pattern, an
     * exclusion, an undeclared per-visitor page — are not visible from the response. This
     * only speaks when the application is in debug mode, so a production log is unaffected.
     */
    private function skip(string $view, string $reason): false
    {
        if ($this->config->get('app.debug') && function_exists('app') && app()->bound('log')) {
            Log::debug("pwax: not prerendering [{$view}] because {$reason}.");
        }

        return false;
    }

    /**
     * Prerender the response's component, returning HTML + serialized state or null on
     * any failure (so the caller falls back to the SPA shell).
     *
     * @return array{html: string, state: string, styles: array<string, string>}|null
     */
    public function render(ComponentResponse $response, Request $request, ?Component $component = null): ?array
    {
        // Passed in by `ComponentResponse::shell()`, which has already compiled it. Asking
        // the response for it again re-renders the Blade view — the compile cache is keyed
        // on the rendered output, so the render has to happen before the cache can be
        // consulted, and every prerendered request paid for the view twice.
        $component ??= $response->component();
        $data = $response->data();

        // Collected before the key is built, not inside `invoke()`, because what this page
        // renders to depends on them. `@pwaxImport` resolves to a URL carrying the imported
        // view's *name*, so editing that view leaves the importing page's source — and its
        // hash — untouched: keyed on the page alone, a changed sub-component would have been
        // served from cache indefinitely. That costs a Blade render per imported component
        // on a cache hit, which is a fraction of the Node spawn the cache exists to avoid,
        // and it keeps the promise the rest of the package's caches make.
        $imports = $this->imports($component);
        $key = $this->cacheKey($component, $data, $imports);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $cacheable = $response->isCacheable() || $data === [];

        if ($cacheable && $this->cache !== null) {
            try {
                $cached = $this->cache->get($key);

                if (is_array($cached) && isset($cached['html'], $cached['state'], $cached['styles'])) {
                    return $this->remember($key, $cached);
                }
            } catch (Throwable $e) {
                $this->report($e);
            }
        }

        $result = $this->invoke($component, $data, $request, $imports);

        if ($result === null) {
            return null;
        }

        if ($cacheable && $this->cache !== null) {
            $ttl = $this->cacheTtl($response);

            try {
                if ($ttl !== null) {
                    $this->cache->put($key, $result, $ttl);
                } else {
                    $this->cache->forever($key, $result);
                }
            } catch (Throwable $e) {
                $this->report($e);
            }
        }

        return $this->remember($key, $result);
    }

    /**
     * The Node bridge's stdin payload, built from the component and controller data.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>|null  $imports  Already collected by the
     *                                                             caller, which also needs
     *                                                             them for the stylesheets.
     * @return array<string, mixed>
     */
    public function payload(Component $component, array $data, Request $request, ?array $imports = null): array
    {
        $imports ??= $this->imports($component);

        return [
            'version' => (string) $this->config->get('pwax.assets.versions.vue', ''),
            'url' => $request->getRequestUri(),
            // The payload the *browser* receives for this page, not the bare component.
            // The difference is the precompiled render function: `Pwax::payload()` prepends
            // it to the inline script for a non-addressable page, so the client renders
            // through `__pwaxRender` while the bridge, handed `toArray()`, compiled the
            // template itself. Two routes to the same markup that agree only as long as the
            // store is in step with the templates — and if it ever is not, they disagree
            // precisely where a mismatch is hardest to read. Sending the same bytes to both
            // removes the question.
            'component' => app(Pwax::class)->payload($component, addressable: false),
            'data' => $data,
            // Every markup fragment the client runtime renders: the root content template,
            // and the loader and error branches of the page component. The bridge builds
            // the same component tree from them, because hydration compares structure and
            // the page component is a fragment — its branch placeholders and its `<!--[-->`
            // anchors have to be in the server's HTML or Vue discards the whole prerender.
            'templates' => app(Shell::class)->templates(),
            // Every component this page reaches through `@pwaxImport`, keyed by the URL its
            // script calls. The browser fetches those modules over HTTP; Node cannot — the
            // URL is a route on the application that is currently serving this request — so
            // the source travels with the payload instead. Without them the bridge cannot
            // even *evaluate* the page: `@pwaxImport` compiles to `window.pwax.component(…)`
            // inside the module, and dereferencing `window` in Node throws before anything
            // renders.
            'imports' => $imports,
        ];
    }

    /**
     * Every component reachable from this one through `@pwaxImport`, keyed by its URL.
     *
     * Transitive, because an imported component may import others, and the bridge has to
     * be able to evaluate the whole graph without reaching the network. Cycles are
     * ordinary here — two components that reference each other is the case
     * {@see Pwax::import()} was designed around — so the visited set is what
     * terminates the walk rather than an assumption that the graph is a tree.
     *
     * A URL that does not resolve is skipped: a forged or stale identifier would 404 for
     * the browser too, and the bridge reports the gap in terms of the component that
     * asked for it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function imports(Component $component): array
    {
        $pwax = app(Pwax::class);

        $collected = [];
        $seen = [];
        $queue = $pwax->importedUrls($component->script);

        while ($queue !== []) {
            $url = array_shift($queue);

            if (isset($seen[$url])) {
                continue;
            }

            if (count($collected) >= self::MAX_IMPORTS) {
                // Said once, in its own terms. Stopping here means the bridge is handed a
                // graph with holes in it, and the failure it reports — "no source was
                // provided for …" — points at the allowlist, which would be the wrong place
                // to look.
                $this->report(new \RuntimeException(sprintf(
                    'Stopped collecting components for the prerender at %d. The page reaches more '
                    . 'than that through @pwaxImport, so it cannot be prerendered.',
                    self::MAX_IMPORTS
                )));

                break;
            }

            $seen[$url] = true;

            $view = $pwax->viewForUrl($url);

            if ($view === null) {
                continue;
            }

            try {
                // The same payload the browser is served for this URL, so the bridge
                // evaluates what the browser evaluates — precompiled render function
                // included.
                $imported = $pwax->compile($view);
                $collected[$url] = $pwax->payload($imported, addressable: false);
            } catch (Throwable $e) {
                $this->report($e);

                continue;
            }

            foreach ($pwax->importedUrls($imported->script) as $nested) {
                $queue[] = $nested;
            }
        }

        return $collected;
    }

    /**
     * How long to keep a prerendered page, in seconds, or null to keep it indefinitely.
     *
     * `ssr.cache.ttl` wins when it is set: it is the application saying how long *any*
     * prerender may be trusted, which is a different question from how long a payload may
     * sit in an HTTP cache. Without it, a page that declared `cacheable()` reuses that
     * lifetime, and a page with no data is kept indefinitely — its key is the component
     * hash, so a changed component simply produces a new entry and the old one ages out.
     */
    private function cacheTtl(ComponentResponse $response): ?int
    {
        $configured = $this->config->get('pwax.ssr.cache.ttl');

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return $response->payloadTtl();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $imports
     */
    private function cacheKey(Component $component, array $data, array $imports = []): string
    {
        // The imported components' own digests are part of the key. Their URLs are derived
        // from view names rather than content, so nothing else in this key moves when one of
        // them is edited.
        $digests = [];

        foreach ($imports as $url => $imported) {
            $digests[] = $url . '=' . (string) ($imported['hash'] ?? '');
        }

        return self::CACHE_PREFIX
            . $component->hash()
            . ':' . hash('xxh128', json_encode($data) ?: '')
            . ':' . hash('xxh128', implode(',', $digests));
    }

    /**
     * @param  array{html: string, state: string, styles: array<string, string>}  $result
     * @return array{html: string, state: string, styles: array<string, string>}
     */
    private function remember(string $key, array $result): array
    {
        return $this->memo[$key] = $result;
    }

    /**
     * Spawn the Node bridge and parse its response.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $imports
     * @return array{html: string, state: string, styles: array<string, string>}|null
     */
    private function invoke(Component $component, array $data, Request $request, array $imports): ?array
    {
        $script = $this->scriptPath();
        $node = (string) ($this->config->get('pwax.ssr.node') ?: 'node');

        if (! is_file($script)) {
            $this->report(new \RuntimeException("The SSR bridge script was not found at {$script}."));

            return $this->fallback();
        }

        $process = new Process(
            [$node, $script],
            base_path(),
            null,
            (string) json_encode($this->payload($component, $data, $request, $imports), JSON_THROW_ON_ERROR),
            // Seconds. `Process` has taken seconds since Symfony 2, and multiplying by a
            // thousand turned the documented five-second budget into eighty-three minutes:
            // a Node process that hung held the worker for as long as it cared to, which is
            // the failure the timeout exists to prevent. `pwax:doctor` passes seconds too.
            (float) $this->config->get('pwax.ssr.timeout', 5),
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->report(new \RuntimeException('The SSR bridge timed out.'));

            return $this->fallback();
        } catch (Throwable $e) {
            $this->report(new \RuntimeException("Could not start Node ({$node}): {$e->getMessage()}"));

            return $this->fallback();
        }

        if (! $process->isSuccessful() && $process->getOutput() === '') {
            $error = trim($process->getErrorOutput());
            $this->report(new \RuntimeException(
                'Node exited with ' . (string) $process->getExitCode() . ': '
                . ($error !== '' ? $error : 'No output.')
            ));

            return $this->fallback();
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->report(new \RuntimeException('The SSR bridge did not return JSON: ' . trim($process->getOutput())));

            return $this->fallback();
        }

        if (($decoded['ok'] ?? false) !== true) {
            $this->report(new \RuntimeException(
                'The SSR bridge failed: ' . (string) ($decoded['message'] ?? 'Unknown error.')
            ));

            return $this->fallback();
        }

        $html = (string) ($decoded['html'] ?? '');

        if ($html === '') {
            $this->report(new \RuntimeException('The SSR bridge returned empty HTML.'));

            return $this->fallback();
        }

        $state = json_encode(
            $decoded['serializedState'] ?? [],
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        // The imported components' stylesheets travel out with the HTML so the shell can
        // put them in the document. The browser attaches them as each module loads, which is
        // fine for a client-rendered page and not for a prerendered one: the sub-component's
        // markup is on screen from the first byte, and for a visitor without JavaScript it
        // is never styled at all. Keyed by URL, which is the key the runtime's style manager
        // uses for an imported component, so it adopts these rather than adding a second
        // copy of each.
        $styles = [];

        foreach ($imports as $url => $imported) {
            if (($imported['style'] ?? '') !== '') {
                $styles[$url] = (string) $imported['style'];
            }
        }

        return ['html' => $html, 'state' => $state, 'styles' => $styles];
    }

    /**
     * The fallback when `ssr.fallback` is `'spa'` — null, so the caller serves the
     * normal shell. `'error'` would throw here; reserved for a future opt-in.
     */
    private function fallback(): null
    {
        $mode = (string) $this->config->get('pwax.ssr.fallback', 'spa');

        if ($mode === 'error') {
            throw new \RuntimeException('The SSR bridge failed and ssr.fallback is set to "error".');
        }

        return null;
    }

    /**
     * The bridge script this application will actually run.
     *
     * Public because `pwax:doctor` has to check the same file. It used to hardcode the
     * package's own `bin/ssr.mjs`, so an application that pointed `ssr.script` elsewhere
     * got a clean bill of health for a script it does not use.
     */
    public function scriptPath(): string
    {
        $configured = $this->config->get('pwax.ssr.script');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return dirname(__DIR__, 3) . '/bin/ssr.mjs';
    }

    private function matchesRoutes(string $view): bool
    {
        /** @var list<string> $patterns */
        $patterns = (array) $this->config->get('pwax.ssr.routes', ['*']);

        return $patterns === [] || $patterns === ['*'] || Str::is($patterns, $view);
    }

    private function isExcluded(string $view): bool
    {
        /** @var list<string> $patterns */
        $patterns = (array) $this->config->get('pwax.ssr.exclude', []);

        return $patterns !== [] && Str::is($patterns, $view);
    }

    /**
     * Warn once per process. A broken Node or missing peer dep would otherwise log on
     * every prerendered request, burying the one line that explains the problem.
     */
    private function report(Throwable $e): void
    {
        if ($this->failureReported) {
            return;
        }

        $this->failureReported = true;

        if (function_exists('app') && app()->bound('log')) {
            Log::warning('pwax: SSR prerender failed, falling back to the SPA shell.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
