<?php

namespace Mxent\Pwax\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\ViewException;
use InvalidArgumentException;
use Mxent\Pwax\Data\Component;
use Mxent\Pwax\Exceptions\ComponentNotAllowed;
use Mxent\Pwax\Exceptions\InvalidComponentId;
use Mxent\Pwax\Pwax;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Serves component assets, the runtime bundle, the manifest and the service worker.
 *
 * Every component endpoint is addressed by a signed identifier. An identifier that does
 * not verify is a `400` and never reaches the view finder — which is what stops these
 * routes from being a way to render arbitrary Blade templates in the application.
 */
class PwaxController extends Controller
{
    public function __construct(
        private readonly Pwax $pwax,
        private readonly Config $config,
        private readonly ViewFactory $views,
    ) {}

    /**
     * Serve a component as a real ES module.
     *
     * The module carries the template, styles and scope alongside the author's script,
     * so the client can `import()` this URL once and have everything it needs. That is
     * what lets the runtime avoid `blob:` and `data:` URLs entirely — a Content-Security
     * -Policy of `script-src 'self'` is enough — while letting the browser HTTP-cache
     * each component on its ETag.
     */
    public function js(Request $request, string $id): SymfonyResponse
    {
        return $this->serve(
            $request,
            $id,
            'application/javascript; charset=utf-8',
            fn (Component $c): string => $this->toModule($c),
            '// pwax: component unavailable'
        );
    }

    /**
     * Serve only a component's compiled CSS.
     */
    public function css(Request $request, string $id): SymfonyResponse
    {
        return $this->serve(
            $request,
            $id,
            'text/css; charset=utf-8',
            fn (Component $c): string => $c->style,
            '/* pwax: component unavailable */'
        );
    }

    /**
     * Serve a component's full payload as JSON.
     */
    public function module(Request $request, string $id): SymfonyResponse
    {
        try {
            $component = $this->pwax->compile($this->pwax->resolve($id));
        } catch (InvalidComponentId $e) {
            return $this->failure($request, $id, $e, 400, 'Invalid component identifier.');
        } catch (ComponentNotAllowed $e) {
            return $this->failure($request, $id, $e, 403, 'Component not available.');
        } catch (Throwable $e) {
            return $this->failure($request, $id, $e, $this->statusFor($e), 'Failed to load component.');
        }

        $payload = $this->pwax->payload($component);
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->cached(new JsonResponse($payload), $request, $body);
    }

    /**
     * Serve the compiled client runtime.
     */
    public function runtime(Request $request): SymfonyResponse
    {
        $path = dirname(__DIR__, 3) . '/dist/pwax.js';

        if (! is_file($path)) {
            Log::error('pwax: dist/pwax.js is missing. Run `npm run build` or reinstall the package.');

            return $this->plain('// pwax: runtime bundle missing', 500, 'application/javascript; charset=utf-8');
        }

        $body = (string) file_get_contents($path);

        $response = new Response($body, 200, ['Content-Type' => 'application/javascript; charset=utf-8']);

        // The bundle is immutable for a given package version, so it can be cached hard.
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->headers->set('ETag', '"' . substr(hash('xxh128', $body), 0, 16) . '"');

        return $this->notModified($request, $response) ?? $response;
    }

    /**
     * Serve the Web App Manifest, rendered from configuration.
     */
    public function manifest(Request $request): SymfonyResponse
    {
        /** @var array<string, mixed> $manifest */
        $manifest = $this->config->get('pwax.manifest', []);

        $manifest = array_filter(
            $manifest,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );

        $body = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        $response = new Response($body, 200, ['Content-Type' => 'application/manifest+json']);
        $response->headers->set('Cache-Control', 'public, max-age=86400');
        $response->headers->set('ETag', '"' . substr(hash('xxh128', $body), 0, 16) . '"');

        return $this->notModified($request, $response) ?? $response;
    }

    /**
     * Serve the service worker.
     */
    public function serviceWorker(): Response
    {
        if (! $this->config->get('pwax.service_worker.enabled', false)) {
            return $this->plain('// pwax: service worker disabled', 404, 'application/javascript; charset=utf-8');
        }

        try {
            $view = (string) ($this->config->get('pwax.service_worker.blade') ?: 'pwax::js.service-worker');
            $body = $this->views->make($view)->render();
        } catch (Throwable $e) {
            Log::error('pwax: failed to render the service worker.', ['exception' => $e]);

            return $this->plain('// pwax: service worker error', 500, 'application/javascript; charset=utf-8');
        }

        $response = new Response($body, 200, ['Content-Type' => 'application/javascript; charset=utf-8']);

        // Allow the worker to control the whole origin regardless of the path it is
        // served from, and never cache it — this file is how updates reach clients.
        $response->headers->set('Service-Worker-Allowed', '/');
        $response->headers->set('Cache-Control', 'no-cache, must-revalidate');

        return $response;
    }

    /**
     * Shared pipeline for the text-bodied component endpoints.
     *
     * @param  callable(Component): string  $extract
     */
    private function serve(
        Request $request,
        string $id,
        string $contentType,
        callable $extract,
        string $fallback
    ): SymfonyResponse {
        try {
            $component = $this->pwax->compile($this->pwax->resolve($id));
        } catch (InvalidComponentId $e) {
            $this->log($request, $id, $e);

            return $this->plain($fallback, 400, $contentType);
        } catch (ComponentNotAllowed $e) {
            $this->log($request, $id, $e);

            return $this->plain($fallback, 403, $contentType);
        } catch (Throwable $e) {
            $this->log($request, $id, $e);

            return $this->plain($fallback, $this->statusFor($e), $contentType);
        }

        $body = $extract($component);

        return $this->cached(new Response($body, 200, ['Content-Type' => $contentType]), $request, $body);
    }

    /**
     * Wrap a component's script in a module that also exposes its template and styles.
     *
     * The author's script is emitted verbatim, so its own `export default` and any named
     * exports keep working, and any `import` statements it contains resolve relative to
     * this URL. The generated bindings are prefixed to make a collision with an author's
     * own identifiers effectively impossible.
     */
    private function toModule(Component $component): string
    {
        $encode = static fn (mixed $value): string => json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );

        return implode("\n", [
            'const __pwaxTemplate = ' . $encode($component->template) . ';',
            'const __pwaxStyle = ' . $encode($component->style) . ';',
            'const __pwaxScope = ' . $encode($component->scopeId) . ';',
            'const __pwaxStyles = ' . $encode($component->externalStyles) . ';',
            'const __pwaxScripts = ' . $encode($component->externalScripts) . ';',
            $component->script,
            'export { __pwaxTemplate, __pwaxStyle, __pwaxScope, __pwaxStyles, __pwaxScripts };',
        ]);
    }

    /**
     * Apply caching headers and short-circuit to 304 when the client already has the body.
     *
     * Typed against the Symfony base class because `JsonResponse` does not extend
     * `Illuminate\Http\Response` — they are siblings, not parent and child.
     */
    private function cached(SymfonyResponse $response, Request $request, string $body): SymfonyResponse
    {
        $ttl = (int) $this->config->get('pwax.cache.asset_ttl', 3600);

        // Components can render differently per user (an admin-only branch, a
        // localised string), so the cache must be per-client, not shared.
        $response->headers->set('Cache-Control', 'private, max-age=' . $ttl);
        $response->headers->set('ETag', '"' . substr(hash('xxh128', $body), 0, 16) . '"');
        $response->headers->set('Vary', Pwax::VARY);

        return $this->notModified($request, $response) ?? $response;
    }

    /**
     * Return a bodyless 304 when the request's `If-None-Match` matches the response ETag.
     */
    private function notModified(Request $request, SymfonyResponse $response): ?SymfonyResponse
    {
        $etag = (string) $response->headers->get('ETag', '');
        $ifNoneMatch = (string) $request->headers->get('If-None-Match', '');

        if ($etag === '' || $ifNoneMatch === '') {
            return null;
        }

        // A conditional request may list several tags, and a cache is allowed to have
        // weakened ours by prefixing `W/`.
        $candidates = array_map(
            static fn (string $tag): string => ltrim(trim($tag), 'W/'),
            explode(',', $ifNoneMatch)
        );

        if (! in_array($etag, $candidates, true) && ! in_array('*', $candidates, true)) {
            return null;
        }

        $notModified = new Response('', 304);

        foreach (['ETag', 'Cache-Control', 'Vary'] as $header) {
            if ($response->headers->has($header)) {
                $notModified->headers->set($header, (string) $response->headers->get($header));
            }
        }

        return $notModified;
    }

    private function failure(Request $request, string $id, Throwable $e, int $status, string $message): JsonResponse
    {
        $this->log($request, $id, $e);

        $response = new JsonResponse(['error' => $message], $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', Pwax::VARY);

        return $response;
    }

    private function plain(string $body, int $status, string $contentType): Response
    {
        $response = new Response($body, $status, ['Content-Type' => $contentType]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * A missing view is a 404, not a 500 — it means the client asked for something that
     * is not there, which is a different problem from the view blowing up while rendering.
     */
    private function statusFor(Throwable $e): int
    {
        if ($e instanceof NotFoundHttpException) {
            return 404;
        }

        $previous = $e instanceof ViewException ? $e->getPrevious() : null;

        if ($e instanceof InvalidArgumentException || $previous instanceof InvalidArgumentException) {
            return str_contains($e->getMessage(), 'not found') ? 404 : 400;
        }

        return 500;
    }

    /**
     * Log the cause server-side. Clients only ever see the generic message, so an
     * exception can never leak a file path, a query, or configuration.
     */
    private function log(Request $request, string $id, Throwable $e): void
    {
        $level = $e instanceof InvalidComponentId || $e instanceof ComponentNotAllowed ? 'warning' : 'error';

        Log::log($level, 'pwax: could not serve component.', [
            'id' => substr($id, 0, 96),
            'ip' => $request->ip(),
            'exception' => $e,
        ]);
    }
}
