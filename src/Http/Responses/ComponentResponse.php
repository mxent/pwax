<?php

namespace Mxent\Pwax\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mxent\Pwax\Data\Component;
use Mxent\Pwax\Pwax;

/**
 * Negotiates between the SPA shell and a JSON component payload.
 *
 * A full browser navigation gets the shell with the component embedded in it, so the
 * first paint costs one request instead of a chain of them. A request from the client
 * runtime gets the payload alone.
 *
 * Both branches set `Vary`. Omitting it — as 1.x did — lets any CDN or shared proxy
 * cache one representation and serve it for the other, which shows raw JSON to a
 * browser or breaks navigation in the runtime.
 */
class ComponentResponse implements Responsable
{
    private bool $forceJson = false;

    private ?int $payloadTtl = null;

    private bool $sharedCache = false;

    private int $status = 200;

    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly Pwax $pwax,
        private readonly string $view,
        private readonly array $data = [],
    ) {}

    /**
     * Always return JSON, whatever the request looks like.
     */
    public function asJson(bool $force = true): self
    {
        $this->forceJson = $force;

        return $this;
    }

    /**
     * Let this page's payload be stored, so the route works offline.
     *
     * Page payloads are `no-store` by default and the service worker honours that, which
     * is why a signed-in user's rendered page never reaches disk. The cost is that such a
     * page is not available offline — correctly, since its content is not knowable
     * without the server.
     *
     * A page that renders the same for everyone has no such problem, and this is how you
     * say so. Only the JSON payload becomes cacheable; the HTML shell stays `no-store`
     * because it carries the CSRF token, and a cached token is worthless at best.
     *
     *     Route::get('/about', fn () => pwax_component('pages.about')->cacheable());
     *
     * Do not call this on a page whose output depends on the visitor. There is no way for
     * the package to tell the difference, and a cache is not a place to find out.
     *
     * @param  bool  $shared  Allow proxies and CDNs to cache it too, not just the browser.
     */
    public function cacheable(int $seconds = 3600, bool $shared = false): self
    {
        $this->payloadTtl = max(0, $seconds);
        $this->sharedCache = $shared;

        return $this;
    }

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    public function component(): Component
    {
        return $this->pwax->compile($this->view, $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->pwax->payload($this->component(), addressable: false);
    }

    public function toResponse($request): JsonResponse|Response
    {
        $component = $this->component();

        return $this->forceJson || $this->pwax->wantsComponent($request)
            ? $this->json($component)
            : $this->shell($component, $request);
    }

    private function json(Component $component): JsonResponse
    {
        $response = new JsonResponse(
            $this->pwax->payload($component, addressable: false),
            $this->status,
            $this->headers
        );

        // A page payload is request-specific by default: it can embed the authenticated
        // user's data, so it must never land in a shared cache and the service worker
        // must not store it. `cacheable()` is how a page that renders the same for
        // everyone opts out of that. (Reusable components served from the /__pwax__
        // endpoints are cached separately, with an ETag.)
        $response->headers->set('Cache-Control', $this->payloadTtl === null
            ? 'no-store, private'
            : sprintf('%s, max-age=%d', $this->sharedCache ? 'public' : 'private', $this->payloadTtl));

        $response->headers->set('Vary', Pwax::VARY);

        return $response;
    }

    private function shell(Component $component, Request $request): Response
    {
        // Everything the first render needs, inline. There is no follow-up request for
        // the page component at all — not even a preloaded one.
        $payload = [
            'url' => $request->getRequestUri(),
            'component' => $this->pwax->payload($component, addressable: false),
        ];

        /** @var ViewFactory $views */
        $views = app(ViewFactory::class);

        $html = $views->make($this->pwax->shell(), [
            'pwaxInitial' => json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ),
            'pwaxComponent' => $component,
        ])->render();

        $response = new Response($html, $this->status, $this->headers);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', Pwax::VARY);

        return $response;
    }
}
