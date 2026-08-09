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
use Mxent\Pwax\Pwa\WebManifest;
use Mxent\Pwax\Pwax;
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
     * Serve the runtime's source map.
     *
     * The bundle ends with a `sourceMappingURL` comment and nothing answered it, so every
     * developer who opened devtools on a Pwax application got a 404 from the package —
     * and, having no map, stepped through minified code. The sources it contains are the
     * package's own, published and MIT licensed, so there is nothing here to withhold.
     */
    public function sourceMap(Request $request): SymfonyResponse
    {
        $path = dirname(__DIR__, 3) . '/dist/pwax.js.map';

        if (! is_file($path)) {
            return $this->plain('{}', 404, 'application/json; charset=utf-8');
        }

        $body = (string) file_get_contents($path);

        $response = new Response($body, 200, ['Content-Type' => 'application/json; charset=utf-8']);

        // Revalidated, not immutable. The bundle is fingerprinted in its URL and this is
        // not — the `sourceMappingURL` comment inside it is a bare filename — so caching
        // this hard would pair a new bundle with last release's mappings and send whoever
        // is debugging to the wrong lines. A 304 costs nothing; the map is only ever
        // fetched with devtools open.
        $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
        $response->headers->set('ETag', '"' . substr(hash('xxh128', $body), 0, 16) . '"');

        return $this->finish($request, $response);
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
            $view = (string) ($this->config->get('pwax.service_worker.blade') ?: 'pwax::js.service-worker');
            $body = $this->views->make($view, ['manifest' => $manifest->get()])->render();
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
     * Each response here already declares an accurate `Content-Type`, so `nosniff` is
     * defence in depth rather than a fix — but these endpoints serve JavaScript that
     * browsers execute, which is exactly where content-type sniffing is worth taking off
     * the table.
     *
     * `Referrer-Policy: no-referrer` is the strictest sane default: a `Referer` header
     * sent to a third party (a script, an image) leaks the application's URL, and a
     * PWA shell is by definition a long-lived document that does not need to send
     * anything. Anyone who needs a different policy can override it.
     *
     * The HTML shell gets `X-Frame-Options: SAMEORIGIN` against clickjacking. Asset
     * responses are inert when framed and do not need it.
     *
     * `Permissions-Policy` shuts off features the application is not asking for. Modern
     * browsers respect it; older ones ignore it.
     *
     * `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Embedder-Policy:
     * require-corp` are the cross-origin isolation pair: they make the document eligible
     * for `SharedArrayBuffer` and high-resolution timers, and they cost nothing when
     * nobody tries to frame or open the page from another origin. Same-origin assets
     * load without `crossorigin` attributes, so this does not break the PWA's own
     * imports.
     */
    private function harden(SymfonyResponse $response, string $kind = 'asset'): SymfonyResponse
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $referrerPolicy = $this->headerFromConfig('pwax.security.referrer_policy', 'no-referrer');

        if ($referrerPolicy !== null) {
            $response->headers->set('Referrer-Policy', $referrerPolicy);
        }

        if ($kind === 'html') {
            $frameOptions = $this->headerFromConfig('pwax.security.frame_options', 'SAMEORIGIN');

            if ($frameOptions !== null) {
                $response->headers->set('X-Frame-Options', $frameOptions);
            }

            if (! $response->headers->has('Permissions-Policy')) {
                $permissionsPolicy = $this->headerFromConfig(
                    'pwax.security.permissions_policy',
                    'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()'
                );

                if ($permissionsPolicy !== null) {
                    $response->headers->set('Permissions-Policy', $permissionsPolicy);
                }
            }

            // `Cross-Origin-Resource-Policy: same-origin` would block any image or font
            // served from another origin, which is the whole point of a CDN. The COOP/COEP
            // pair is the right tool for cross-origin isolation, not CORP.
            $coop = $this->headerFromConfig('pwax.security.cross_origin_opener_policy', 'same-origin');

            if ($coop !== null) {
                $response->headers->set('Cross-Origin-Opener-Policy', $coop);
            }

            $coep = $this->headerFromConfig('pwax.security.cross_origin_embedder_policy', 'require-corp');

            if ($coep !== null) {
                $response->headers->set('Cross-Origin-Embedder-Policy', $coep);
            }
        }

        return $response;
    }

    /**
     * A configured security header, with `null` to omit and `''` to omit.
     */
    private function headerFromConfig(string $key, string $default): ?string
    {
        // `Repository::get()` returns the default when the value is `null` (and the
        // documentation says so). Distinguish "the user set this to null to suppress the
        // header" from "the user has not said anything either way" with `has()`.
        $value = $this->config->has($key) ? $this->config->get($key) : $default;

        if ($value === null || $value === false) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
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
