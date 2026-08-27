<?php

namespace Mxent\Pwax\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\ViewException;
use InvalidArgumentException;
use Mxent\Pwax\Data\Component;
use Mxent\Pwax\Exceptions\ComponentNotAllowed;
use Mxent\Pwax\Exceptions\InvalidComponentId;
use Mxent\Pwax\Pwa\AssetManifest;
use Mxent\Pwax\Pwa\ServiceWorker;
use Mxent\Pwax\Pwa\WebManifest;
use Mxent\Pwax\Pwax;
use Mxent\Pwax\Support\RenderFunctionStore;
use Mxent\Pwax\Support\SecurityHeaders;
use Mxent\Pwax\Support\Shell;
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
        private readonly ServiceWorker $worker,
        private readonly RenderFunctionStore $renderFunctions,
        private readonly SecurityHeaders $security,
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
        $contentType = 'application/javascript; charset=utf-8';

        try {
            $component = $this->pwax->compile($this->pwax->resolve($id));
        } catch (InvalidComponentId $e) {
            $this->log($request, $id, $e);

            return $this->plain('// pwax: component unavailable', 400, $contentType);
        } catch (ComponentNotAllowed $e) {
            $this->log($request, $id, $e);

            return $this->plain('// pwax: component unavailable', 403, $contentType);
        } catch (Throwable $e) {
            $this->log($request, $id, $e);

            return $this->plain('// pwax: component unavailable', $this->statusFor($e), $contentType);
        }

        $body = $this->toModule($component);

        return $this->cached(new Response($body, 200, ['Content-Type' => $contentType]), $request, $body);
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

        return $this->finish($request, $response);
    }

    /**
     * Serve the JSON renderer.
     *
     * A second bundle rather than part of the runtime: it carries @json-render/vue,
     *
     * @json-render/core and zod, which is around 82 kB gzipped against the runtime's
     * 9.7 kB, and only an application that renders a `<PwaxJson>` ever needs it. The
     * client fetches it the first time one is rendered.
     *
     * 404 when the feature is off, rather than serving a bundle nothing can reach: the
     * runtime is told the same thing through a null `json.runtime`, so a request that
     * gets here at all is one somebody wrote by hand.
     */
    public function jsonRuntime(Request $request): SymfonyResponse
    {
        if (! $this->config->get('pwax.json.enabled', true)) {
            return $this->plain(
                '// pwax: JSON rendering is disabled (pwax.json.enabled)',
                404,
                'application/javascript; charset=utf-8'
            );
        }

        $path = dirname(__DIR__, 3) . '/dist/pwax-json.js';

        if (! is_file($path)) {
            Log::error('pwax: dist/pwax-json.js is missing. Run `npm run build` or reinstall the package.');

            return $this->plain('// pwax: JSON renderer bundle missing', 500, 'application/javascript; charset=utf-8');
        }

        $body = (string) file_get_contents($path);

        $response = new Response($body, 200, ['Content-Type' => 'application/javascript; charset=utf-8']);

        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->headers->set('ETag', '"' . substr(hash('xxh128', $body), 0, 16) . '"');

        return $this->finish($request, $response);
    }

    /**
     * Serve the runtime's source map.
     *
     * The bundle ends with a `sourceMappingURL` comment and nothing answered it, so every
     * developer who opened devtools on a Pwax application got a 404 from the package —
     * and, having no map, stepped through minified code. The sources it contains are the
     * package's own, published and MIT licensed, so there is nothing here to withhold.
     */
    public function sourceMap(Request $request): SymfonyResponse
    {
        return $this->map($request, dirname(__DIR__, 3) . '/dist/pwax.js.map');
    }

    /**
     * Serve the JSON renderer's source map.
     */
    public function jsonRuntimeSourceMap(Request $request): SymfonyResponse
    {
        return $this->map($request, dirname(__DIR__, 3) . '/dist/pwax-json.js.map');
    }

    /**
     * Serve one of the bundles' source maps.
     *
     * Revalidated, not immutable. A bundle is fingerprinted in its URL and its map is not
     * — the `sourceMappingURL` comment inside it is a bare filename — so caching this hard
     * would pair a new bundle with last release's mappings and send whoever is debugging
     * to the wrong lines. A 304 costs nothing; a map is only ever fetched with devtools
     * open. The sources it contains are the package's own, published and MIT licensed, so
     * there is nothing here to withhold.
     */
    private function map(Request $request, string $path): SymfonyResponse
    {
        if (! is_file($path)) {
            return $this->plain('{}', 404, 'application/json; charset=utf-8');
        }

        $body = (string) file_get_contents($path);

        $response = new Response($body, 200, ['Content-Type' => 'application/json; charset=utf-8']);
        $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
        $response->headers->set('ETag', '"' . substr(hash('xxh128', $body), 0, 16) . '"');

        return $this->finish($request, $response);
    }

    /**
     * Serve the service worker's source map.
     */
    public function workerSourceMap(Request $request): SymfonyResponse
    {
        return $this->map($request, $this->worker->path() . '.map');
    }

    /**
     * Serve the Web App Manifest, rendered from configuration.
     */
    public function manifest(Request $request, WebManifest $manifest): SymfonyResponse
    {
        $body = $manifest->toJson();

        $response = new Response($body, 200, ['Content-Type' => 'application/manifest+json']);
        $response->headers->set('Cache-Control', 'public, max-age=86400');
        $response->headers->set('ETag', '"' . $manifest->hash() . '"');

        // `lang`, `name`, `short_name` and `description` all follow the application locale,
        // and this is served `public` for a day. Today the route sits outside the `web`
        // group, so nothing has set a locale by the time it renders and every visitor gets
        // the same document — but that is a property of the middleware list, not of this
        // response, and `routes.static_middleware` is a config key. One `Vary` costs
        // nothing and means adding locale middleware there cannot turn a shared cache into
        // a machine for serving one visitor's language to the next.
        $response->headers->set('Vary', 'Accept-Language');

        return $this->finish($request, $response);
    }

    /**
     * Serve the asset manifest that drives the service worker.
     *
     * This is the list the worker installs the application from — every vendor bundle,
     * the runtime, the offline shell and every component, each with a content hash. It
     * is what makes the app available offline after a single visit rather than only the
     * pages the visitor happened to open.
     */
    public function assetManifest(Request $request, AssetManifest $manifest): SymfonyResponse
    {
        if (! $this->config->get('pwax.service_worker.enabled', false)) {
            return $this->plain('{}', 404, 'application/json; charset=utf-8');
        }

        try {
            $built = $manifest->get();

            $body = (string) json_encode(
                $built,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            );

            $hash = (string) ($built['hash'] ?? '');
        } catch (Throwable $e) {
            Log::error('pwax: failed to build the asset manifest.', ['exception' => $e]);

            return $this->plain('{}', 500, 'application/json; charset=utf-8');
        }

        $response = new Response($body, 200, ['Content-Type' => 'application/json; charset=utf-8']);

        // Revalidated rather than cached: this document is how a client discovers that a
        // new build exists, so serving a stale copy would delay every update by its TTL.
        $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
        $response->headers->set('ETag', '"' . $hash . '"');

        return $this->finish($request, $response);
    }

    /**
     * Serve the offline application shell.
     *
     * The SPA shell with no session and no page component: no CSRF token, no controller
     * data, byte-identical for every visitor. The worker precaches this and serves it for
     * any navigation it cannot reach the network for, and the client runtime takes over
     * routing from there.
     *
     * Precaching a real application URL instead — which is what `precache => ['/']` used
     * to do — stores one signed-in user's HTML on disk, where the next user of the same
     * device is served it. The shell exists so that offline navigation does not require
     * that trade.
     */
    public function shell(Request $request, Shell $shell): SymfonyResponse
    {
        if (! $this->config->get('pwax.service_worker.shell.enabled', true)) {
            return $this->plain('', 404, 'text/html; charset=utf-8');
        }

        try {
            $body = $this->views->make($this->pwax->shell(), [
                'pwaxInitial' => null,
                'pwaxComponent' => null,
                'pwaxShell' => $shell,
            ])->render();
        } catch (Throwable $e) {
            Log::error('pwax: failed to render the offline shell.', ['exception' => $e]);

            return $this->plain('', 500, 'text/html; charset=utf-8');
        }

        $response = new Response($body, 200, ['Content-Type' => 'text/html; charset=utf-8']);

        // Public, because there is deliberately nothing user-specific in it. That is the
        // whole reason this route exists rather than precaching `/`.
        $response->headers->set('Cache-Control', 'public, max-age=0, must-revalidate');
        $response->headers->set('ETag', '"' . substr(hash('xxh128', $body), 0, 16) . '"');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $this->finish($request, $response);
    }

    /**
     * Serve the service worker.
     * The worker source carries the current asset-manifest hash. That is what makes a
     * deploy reach existing installs: a browser only treats a worker as new if its bytes
     * differ from the one it already has, so a worker whose source never changes would
     * leave every client running the build it first installed until something else
     * happened to evict it. With the hash embedded, adding a component or changing a file
     * changes the worker, the browser installs it, and the new manifest is applied.
     */
    public function serviceWorker(Request $request, AssetManifest $manifest): SymfonyResponse
    {
        if (! $this->config->get('pwax.service_worker.enabled', false)) {
            return $this->plain('// pwax: service worker disabled', 404, 'application/javascript; charset=utf-8');
        }

        try {
            $body = $this->worker->build($manifest->get());
        } catch (Throwable $e) {
            Log::error('pwax: failed to render the service worker.', ['exception' => $e]);

            return $this->plain('// pwax: service worker error', 500, 'application/javascript; charset=utf-8');
        }

        $response = new Response($body, 200, ['Content-Type' => 'application/javascript; charset=utf-8']);

        // Allow the worker to control the whole origin regardless of the path it is
        // served from, and never cache it — this file is how updates reach clients.
        $response->headers->set('Service-Worker-Allowed', '/');
        $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
        $response->headers->set('ETag', '"' . substr(hash('xxh128', $body), 0, 16) . '"');

        return $this->finish($request, $response);
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

        // The precompiled render function, when `pwax:compile` has produced one. Emitted
        // as source rather than a string: this is a real ES module, so the module loader
        // evaluates it and no `Function` constructor is involved — which is what lets an
        // application drop `script-src 'unsafe-eval'` and ship runtime-only Vue.
        return implode("\n", array_filter([
            $this->renderFunctions->bindings($component->template),
            'const __pwaxTemplate = ' . $encode($component->template) . ';',
            'const __pwaxStyle = ' . $encode($component->style) . ';',
            'const __pwaxScope = ' . $encode($component->scopeId) . ';',
            'const __pwaxStyles = ' . $encode($component->externalStyles) . ';',
            'const __pwaxScripts = ' . $encode($component->externalScripts) . ';',
            $component->script,
            'export { __pwaxTemplate, __pwaxStyle, __pwaxScope, __pwaxStyles, __pwaxScripts };',
        ]));
    }

    /**
     * Apply caching headers and short-circuit to 304 when the client already has the body.
     */
    private function cached(SymfonyResponse $response, Request $request, string $body): SymfonyResponse
    {
        $ttl = (int) $this->config->get('pwax.cache.asset_ttl', 3600);

        // Components can render differently per user (an admin-only branch, a
        // localised string), so the cache must be per-client, not shared.
        $response->headers->set('Cache-Control', 'private, max-age=' . $ttl);
        $response->headers->set('ETag', '"' . substr(hash('xxh128', $body), 0, 16) . '"');
        $response->headers->set('Vary', Pwax::VARY);

        return $this->finish($request, $response);
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
        // weakened ours by prefixing `W/`. Stripped as a prefix, not with `ltrim()`: that
        // takes a *character list*, and the only reason it was ever correct here is that
        // every tag this controller emits is quoted, so the `"` stopped it before it could
        // reach a `W` in the value. That is a property of today's tags, not of the parsing,
        // and it is not one the next person changing the tag format should have to know.
        $candidates = array_map(
            static function (string $tag): string {
                $tag = trim($tag);

                return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
            },
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

    private function plain(string $body, int $status, string $contentType): SymfonyResponse
    {
        $response = new Response($body, $status, ['Content-Type' => $contentType]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $this->harden($response);
    }

    /**
     * Answer a conditional request if we can, and harden whatever goes out.
     *
     * Every response this controller produces passes through here or through `plain()`,
     * which is the point: a header that is meant to be on all of them should not depend on
     * whoever adds the next endpoint remembering it.
     */
    private function finish(Request $request, SymfonyResponse $response): SymfonyResponse
    {
        return $this->harden($this->notModified($request, $response) ?? $response, $this->kindOf($response));
    }

    /**
     * What kind of response this is, for hardening that depends on the body shape.
     */
    private function kindOf(SymfonyResponse $response): string
    {
        $type = (string) $response->headers->get('Content-Type', '');

        return str_starts_with($type, 'text/html') ? 'html' : 'asset';
    }

    /**
     * Headers every Pwax endpoint carries.
     *
     * The same set the application's own pages get — see {@see SecurityHeaders}, which is
     * where they are decided and why they live in one place rather than here.
     */
    private function harden(SymfonyResponse $response, string $kind = 'asset'): SymfonyResponse
    {
        return $this->security->apply($response, document: $kind === 'html');
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
