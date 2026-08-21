<?php

namespace Mxent\Pwax\Pwa\Ssr;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mxent\Pwax\Data\Component;
use Mxent\Pwax\Http\Responses\ComponentResponse;
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
 * cached only when it is cacheable, and for the TTL the response declared.
 */
class Prerenderer
{
    private const CACHE_PREFIX = 'pwax:ssr:';

    /**
     * A per-request memo, so a response that reads its own prerender twice pays once.
     *
     * @var array<string, array{html: string, state: string}>
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

        if ($response->isSpaOnly()) {
            return false;
        }

        $view = $response->view();

        if (! $this->matchesRoutes($view)) {
            return false;
        }

        if ($this->isExcluded($view)) {
            return false;
        }

        // A page rendered with no data is a pure function of its view name: every visitor
        // produces the same bytes, so it is safe to prerender. A page rendered *with*
        // data is only safe when the route has declared it visitor-independent through
        // `cacheable()` — the same claim the compile cache relies on.
        return $response->data() === [] || $response->isCacheable();
    }

    /**
     * Prerender the response's component, returning HTML + serialized state or null on
     * any failure (so the caller falls back to the SPA shell).
     *
     * @return array{html: string, state: string}|null
     */
    public function render(ComponentResponse $response, Request $request): ?array
    {
        $component = $response->component();
        $data = $response->data();
        $key = $this->cacheKey($component, $data);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $cacheable = $response->isCacheable() || $data === [];

        if ($cacheable && $this->cache !== null) {
            try {
                $cached = $this->cache->get($key);

                if (is_array($cached) && isset($cached['html'], $cached['state'])) {
                    return $this->remember($key, $cached);
                }
            } catch (Throwable $e) {
                $this->report($e);
            }
        }

        $result = $this->invoke($component, $data, $request);

        if ($result === null) {
            return null;
        }

        if ($cacheable && $this->cache !== null) {
            $ttl = $response->payloadTtl();

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
     * @return array<string, mixed>
     */
    public function payload(Component $component, array $data, Request $request): array
    {
        $shell = app(Shell::class);
        $templates = $shell->templates();
        $content = $templates['content'] ?? '<main><router-view></router-view></main>';

        return [
            'version' => (string) $this->config->get('pwax.assets.versions.vue', ''),
            'url' => $request->getRequestUri(),
            'component' => $component->toArray(),
            'data' => $data,
            // The content template the client app renders as its root. The server must
            // render the same wrapper so the prerendered HTML hydrates without a mismatch.
            'contentTemplate' => $content,
        ];
    }

    /**
     * Flush the prerender cache. Called by `pwax:clear`.
     */
    public function flush(): void
    {
        $this->memo = [];

        if ($this->cache === null) {
            return;
        }

        try {
            // Content-addressed keys, no tags — same situation as the component cache.
            // `clear()` flushes the whole store, which is acceptable under an explicit
            // `pwax:clear`.
            $this->cache->clear();
        } catch (Throwable) {
            // Nothing to flush, or no permission.
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cacheKey(Component $component, array $data): string
    {
        return self::CACHE_PREFIX . $component->hash() . ':' . hash('xxh128', json_encode($data) ?: '');
    }

    /**
     * @param  array{html: string, state: string}  $result
     * @return array{html: string, state: string}
     */
    private function remember(string $key, array $result): array
    {
        return $this->memo[$key] = $result;
    }

    /**
     * Spawn the Node bridge and parse its response.
     *
     * @param  array<string, mixed>  $data
     * @return array{html: string, state: string}|null
     */
    private function invoke(Component $component, array $data, Request $request): ?array
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
            (string) json_encode($this->payload($component, $data, $request), JSON_THROW_ON_ERROR),
            (int) ((float) $this->config->get('pwax.ssr.timeout', 5) * 1000),
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

        return ['html' => $html, 'state' => $state];
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

    private function scriptPath(): string
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
