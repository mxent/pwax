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

        // A page payload is request-specific: it can embed the authenticated user's
        // data. It must never land in a shared cache. (Reusable components served from
        // the /__pwax__ endpoints are cached separately, with an ETag.)
        $response->headers->set('Cache-Control', 'no-store, private');
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
