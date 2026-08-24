<?php

namespace Mxent\Pwax\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mxent\Pwax\Pwax;
use Mxent\Pwax\Support\SecurityHeaders;
use Symfony\Component\HttpFoundation\Response;

/**
 * Translates framework responses into something the client runtime can act on.
 *
 * The client navigates with `fetch`, which follows redirects transparently and hands
 * back whatever HTML sits at the far end. Left alone, an `auth` middleware redirect
 * reaches the browser as a login page's HTML where a JSON payload was expected, and
 * surfaces as an opaque parse failure rather than as a sign-in.
 *
 * This middleware closes that gap:
 *
 *   - A redirect to a URL inside the application becomes `{"redirect": "/path"}`, which
 *     the SPA router follows without a page reload.
 *   - A redirect off-site becomes `409` plus an `X-Pwax-Location` header, which the
 *     client turns into a full navigation. `fetch` cannot be told not to follow a 3xx,
 *     so the status has to be one it will hand back untouched.
 *
 * It also guarantees `Vary` on every response from a Pwax route, including ones the
 * framework generated itself, such as `abort(404)`.
 *
 * What this middleware deliberately does *not* handle are redirects produced by an
 * exception rather than a response — an expired CSRF token from `VerifyCsrfToken`, or an
 * unauthenticated request from `auth`. Both throw, so the exception unwinds past this
 * middleware without `$next()` ever returning, and the redirect is created by the
 * exception handler outside the pipeline entirely. The client covers those: it treats a
 * followed redirect that comes back as HTML as an instruction to reload.
 */
class HandlePwaxRequests
{
    public function __construct(
        private readonly Pwax $pwax,
        private readonly SecurityHeaders $security,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $this->vary($response);

        if (! $this->pwax->wantsComponent($request)) {
            return $response;
        }

        if ($response->isRedirection()) {
            return $this->redirect($request, $response);
        }

        return $response;
    }

    private function redirect(Request $request, Response $response): Response
    {
        $location = (string) $response->headers->get('Location', '');

        if ($location === '') {
            return $response;
        }

        if (! $this->isInternal($request, $location)) {
            return $this->fullReload($location);
        }

        // 303 on a redirect after POST/PUT/PATCH/DELETE tells the client the follow-up
        // must be a GET, matching what a browser would have done on its own.
        $status = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 303 : 302;

        return $this->json(['redirect' => $this->toPath($location)], $status);
    }

    private function fullReload(string $location): Response
    {
        return $this->json(['location' => $location], 409)
            ->header(Pwax::LOCATION_HEADER, $location);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function json(array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $this->vary($response);

        // These two responses are the package's, not the application's — the only ones this
        // middleware creates rather than passes through — so they carry what every other
        // Pwax response carries. The rest of the `web` group is left exactly as it was:
        // hardening an application's own routes is not this middleware's business.
        return $this->security->apply($response, document: false);
    }

    private function isInternal(Request $request, string $location): bool
    {
        $host = parse_url($location, PHP_URL_HOST);

        return $host === null || $host === $request->getHttpHost();
    }

    private function toPath(string $location): string
    {
        $path = parse_url($location, PHP_URL_PATH) ?: '/';
        $query = parse_url($location, PHP_URL_QUERY);
        $fragment = parse_url($location, PHP_URL_FRAGMENT);

        return $path
            . ($query !== null && $query !== '' ? '?' . $query : '')
            . ($fragment !== null && $fragment !== '' ? '#' . $fragment : '');
    }

    private function vary(Response $response): void
    {
        $existing = (string) $response->headers->get('Vary', '');

        if ($existing === '') {
            $response->headers->set('Vary', Pwax::VARY);

            return;
        }

        $headers = array_map(trim(...), explode(',', $existing . ', ' . Pwax::VARY));

        $response->headers->set('Vary', implode(', ', array_values(array_unique(array_filter($headers)))));
    }
}
