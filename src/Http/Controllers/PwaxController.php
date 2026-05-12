<?php

namespace Mxent\Pwax\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class PwaxController extends Controller
{
    /**
     * Serve the minified <script> block of a Blade view as JavaScript.
     */
    public function js(Request $request, string $name): Response
    {
        try {
            $view = $this->resolveViewName($name);
            $contents = view($view)->render();

            $blocks = pwaxExtractBlocks('script', $contents);
            $script = pwaxMinifyJs(implode("\n;\n", $blocks));

            return $this->withCacheHeaders(
                response($script, 200, ['Content-Type' => 'application/javascript; charset=utf-8']),
                $request,
                $script
            );
        } catch (\InvalidArgumentException $e) {
            return response('// Invalid component', 400)
                ->header('Content-Type', 'application/javascript; charset=utf-8');
        } catch (\Throwable $e) {
            Log::error('pwax: error loading script component', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return response('// Error loading script', 500)
                ->header('Content-Type', 'application/javascript; charset=utf-8');
        }
    }

    /**
     * Serve the minified <style> block of a Blade view as CSS.
     */
    public function css(Request $request, string $name): Response
    {
        try {
            $view = $this->resolveViewName($name);
            $contents = view($view)->render();

            $blocks = pwaxExtractBlocks('style', $contents);
            $style = pwaxMinifyCss(implode("\n", $blocks));

            return $this->withCacheHeaders(
                response($style, 200, ['Content-Type' => 'text/css; charset=utf-8']),
                $request,
                $style
            );
        } catch (\InvalidArgumentException $e) {
            return response('/* Invalid component */', 400)
                ->header('Content-Type', 'text/css; charset=utf-8');
        } catch (\Throwable $e) {
            Log::error('pwax: error loading stylesheet component', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return response('/* Error loading stylesheet */', 500)
                ->header('Content-Type', 'text/css; charset=utf-8');
        }
    }

    /**
     * Serve a Vue module (template/script/style/scripts/styles JSON).
     */
    public function module(string $name): JsonResponse|Response
    {
        try {
            $view = $this->resolveViewName($name);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid component'], 400);
        }

        return vue($view, null, ['bypass' => true]);
    }

    /**
     * Serve the dynamically rendered PWA manifest.
     */
    public function manifest(): JsonResponse
    {
        $manifest = config('pwax.manifest', []);

        return response()->json($manifest, 200, [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Serve the service worker JavaScript (rendered from a Blade view).
     */
    public function serviceWorker(): Response
    {
        if (! config('pwax.service_worker.enabled', false)) {
            return response('// service worker disabled', 404)
                ->header('Content-Type', 'application/javascript; charset=utf-8');
        }

        try {
            $view = config('pwax.service_worker.blade') ?: 'pwax::js.service-worker';
            $contents = view($view)->render();
            return response($contents, 200, [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Service-Worker-Allowed' => '/',
                'Cache-Control' => 'no-cache',
            ]);
        } catch (\Throwable $e) {
            Log::error('pwax: error rendering service worker', [
                'error' => $e->getMessage(),
            ]);
            return response('// error rendering service worker', 500)
                ->header('Content-Type', 'application/javascript; charset=utf-8');
        }
    }

    /**
     * Decode and validate a URL-encoded view name. Underscore encoding:
     *   "_x_"  => "."
     *   "__x__" => "::"
     */
    protected function resolveViewName(string $name): string
    {
        $decoded = str_replace(['__x__', '_x_'], ['::', '.'], $name);
        return pwaxValidateViewName($decoded);
    }

    /**
     * Apply Cache-Control + ETag headers and emit 304 on match.
     */
    protected function withCacheHeaders(Response $response, Request $request, string $body): Response
    {
        $ttl = (int) config('pwax.cache.asset_ttl', 3600);
        $etag = '"' . sha1($body) . '"';

        $response->headers->set('Cache-Control', 'public, max-age=' . $ttl);
        $response->headers->set('ETag', $etag);

        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch && trim($ifNoneMatch) === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=' . $ttl,
            ]);
        }

        return $response;
    }
}
