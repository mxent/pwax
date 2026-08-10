<?php

namespace Mxent\Pwax\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mxent\Pwax\Data\Component;
use Mxent\Pwax\Data\Head;
use Mxent\Pwax\Pwa\HeadMeta;
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

    private ?string $title = null;

    private ?string $description = null;

    private ?string $canonical = null;

    /** @var list<array{attribute: string, key: string, content: string}> */
    private array $meta = [];

    private bool $storable = true;

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
     *     Route::get('/about', fn () => pwaxRender('pages.about')->cacheable());
     *
     * Do not call this on a page whose output depends on the visitor. There is no way for
     * the package to tell the difference, and a cache is not a place to find out.
     *
     * Calling this also opts the page's *compiled* form back into the component cache,
     * which a page rendered with controller data is otherwise kept out of. Same claim,
     * used twice: you have said this page renders the same for everyone.
     *
     * @param  bool  $shared  Allow proxies and CDNs to cache it too, not just the browser.
     */
    public function cacheable(int $seconds = 3600, bool $shared = false): self
    {
        $this->payloadTtl = max(0, $seconds);
        $this->sharedCache = $shared;

        return $this;
    }

    /**
     * The document title for this page.
     *
     * Applied on the first paint through `<title>`, and again on every client-side
     * navigation — the runtime reads it from the payload. Setting it only in the shell
     * would leave the title correct once and stale for the rest of the session, which is
     * worse than not setting it at all.
     *
     *     Route::get('/about', fn () => pwaxRender('pages.about')->title('About us'));
     *
     * `pwax.head.title_template` wraps it, so a per-page title need not repeat the site
     * name on every route.
     */
    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * This page's description, for `<meta name="description">` and Open Graph.
     *
     *     Route::get('/about', fn () => pwaxRender('pages.about')
     *         ->title('About us')
     *         ->description('Who we are and what we build.'));
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * The canonical URL for this page.
     *
     * Worth setting on any route reachable at more than one URL — a paginated list, a
     * filtered index, anything that takes a tracking parameter. Nothing is emitted unless
     * you say so: guessing from the request would make every query string its own
     * canonical, which is the problem rather than the fix.
     */
    public function canonical(string $url): self
    {
        $this->canonical = $url;

        return $this;
    }

    /**
     * Add a `<meta name="…">` tag, or several.
     *
     *     ->meta('robots', 'noindex')
     *     ->meta(['robots' => 'noindex', 'author' => 'Ada Lovelace'])
     *
     * @param  string|array<string, string|null>  $name
     */
    public function meta(string|array $name, ?string $content = null): self
    {
        return $this->tag('name', is_array($name) ? $name : [$name => $content]);
    }

    /**
     * Add a `<meta property="…">` tag, or several — Open Graph and friends.
     *
     * `og:title`, `og:description`, `og:url`, `og:type` and `og:site_name` are derived for
     * you from the title, description and canonical URL. Set one here to override it, or
     * use this for the ones that cannot be derived:
     *
     *     ->property('og:image', asset('images/og.png'))
     *
     * @param  string|array<string, string|null>  $property
     */
    public function property(string|array $property, ?string $content = null): self
    {
        return $this->tag('property', is_array($property) ? $property : [$property => $content]);
    }

    /**
     * @param  array<string, string|null>  $tags
     */
    private function tag(string $attribute, array $tags): self
    {
        foreach ($tags as $key => $content) {
            if ($content === null || $content === '') {
                continue;
            }

            $this->meta[] = ['attribute' => $attribute, 'key' => (string) $key, 'content' => $content];
        }

        return $this;
    }

    /**
     * This page's metadata, defaults filled in and Open Graph derived.
     *
     * The same object the shell view renders and the payload carries, so a reload and a
     * client-side navigation cannot disagree about what the document says it is.
     */
    public function head(): Head
    {
        return app(HeadMeta::class)->resolve(new Head(
            title: $this->title,
            description: $this->description,
            canonical: $this->canonical,
            meta: $this->meta,
        ));
    }

    /**
     * Keep this page out of the service worker's cache entirely.
     *
     * The worker stores pages as they are visited so that everywhere you have been works
     * offline. Caches are shared across visitors, so a page that must not reach disk at
     * all — a one-time code, a recovery key, somebody else's medical record on a shared
     * terminal — has to opt out by name.
     */
    public function offline(bool $offline = true): self
    {
        $this->storable = $offline;

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
        // `cacheable()` is a stronger claim than the compile cache needs. A page whose
        // payload may sit in a shared HTTP cache and be handed to the next visitor has
        // already been declared visitor-independent by whoever wrote the route — so its
        // compiled output is worth storing even though it was rendered with data, which
        // is otherwise the signal that it is not. Without this a documentation site
        // rendering the same page from the same data on every request would compile it
        // from scratch every time.
        return $this->pwax->compile(
            $this->view,
            $this->data,
            store: $this->data === [] || $this->payloadTtl !== null,
        );
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
        $payload = $this->pwax->payload($component, addressable: false);
        $head = $this->head();

        if ($this->title !== null) {
            $payload['title'] = $head->title;
        }

        // Sent whenever this page declared anything of its own. A client-side navigation
        // replaces the document's contents without replacing its head, so whatever the
        // previous page said about itself stays in the DOM until something says otherwise
        // — the description, the canonical URL and every Open Graph tag included.
        if ($this->description !== null || $this->canonical !== null || $this->meta !== []) {
            $payload['head'] = $head->toArray();
        }

        $response = new JsonResponse($payload, $this->status, $this->headers);

        if (! $this->storable) {
            // Read by the service worker, which honours it above everything else — a page
            // marked this way is refused even by the runtime cache that ordinarily stores
            // whatever you have visited.
            $response->headers->set('X-Pwax-Cache', 'none');
        }

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
        $head = $this->head();

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
            'pwaxHead' => $head,
            // Still passed, and still the resolved title. A shell published before `$pwaxHead`
            // existed reads this one, and there is no reason to break it.
            'pwaxTitle' => $head->title,
        ])->render();

        $response = new Response($html, $this->status, $this->headers);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', Pwax::VARY);

        if (! $this->storable) {
            $response->headers->set('X-Pwax-Cache', 'none');
        }

        return $response;
    }
}
