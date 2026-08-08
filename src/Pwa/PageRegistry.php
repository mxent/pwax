<?php

namespace Mxent\Pwax\Pwa;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

/**
 * Finds the routes that render a Pwax page, so all of them can be made available offline.
 *
 * Caching pages as they are visited only ever covers where a visitor has already been.
 * Install the app from the home page, go offline, open Settings, and you get an error —
 * the one page you needed was the one you had not opened yet. Listing every route by hand
 * in `pages.urls` works and nobody does it, because the list goes stale the moment someone
 * adds a route.
 *
 * The route table already knows the URLs. What it does not know is which of them are Pwax
 * pages, so this reads the route's action and looks for a literal view name handed to
 * `pwaxRender()`, `Pwax::render()` or `pwax()`:
 *
 *     Route::get('/settings', fn () => pwaxRender('pages.settings'));
 *
 * That view name is the point. It is what lets `service_worker.components` scope pages the
 * same way it scopes components — `'all'` takes every page, `['pages.*']` takes only the
 * ones whose view matches — so one setting governs both halves of what goes offline.
 *
 * The limitation is the honest one for a static read: the view name has to be a literal.
 * A route that computes it, or renders through a service, cannot be discovered and belongs
 * in `pages.urls`. Nothing is guessed — a route this cannot read is simply not listed, and
 * `php artisan pwax:precache` shows exactly what was found.
 */
class PageRegistry
{
    /**
     * A call to one of the three ways of rendering a page, with a literal view name.
     *
     * Longest alternative first: `pwax` would otherwise match the start of `pwaxRender`
     * and capture nothing.
     */
    private const RENDER_CALL = '/(?:pwaxRender|Pwax::render|\bpwax)\s*\(\s*[\'"]([^\'"]+)[\'"]/';

    public function __construct(
        private readonly Router $router,
        private readonly Config $config,
        private readonly ComponentRegistry $components,
    ) {}

    /**
     * Every discoverable page route, as a URL path.
     *
     * @return list<array{url: string, view: string}>
     */
    public function all(): array
    {
        $pages = [];

        foreach ($this->router->getRoutes() as $route) {
            $url = $this->urlFor($route);

            if ($url === null) {
                continue;
            }

            foreach ($this->viewsRenderedBy($route) as $view) {
                $pages[$url] ??= ['url' => $url, 'view' => $view];
            }
        }

        ksort($pages);

        return array_values($pages);
    }

    /**
     * The discoverable pages the configuration actually wants precached.
     *
     * Scoped by the same selection that governs components, so `components => ['pages.*']`
     * narrows the routes as well as the modules and there is one answer to "what goes
     * offline" rather than two that can disagree.
     *
     * @return list<array{url: string, view: string}>
     */
    public function precachable(): array
    {
        if (! $this->config->get('pwax.service_worker.pages.discover', true)) {
            return [];
        }

        $allowed = array_column($this->components->precachable(), 'view');

        if ($allowed === []) {
            return [];
        }

        $allowed = array_flip($allowed);

        return array_values(array_filter(
            $this->all(),
            static fn (array $page): bool => isset($allowed[$page['view']])
        ));
    }

    /**
     * The path this route answers, or null if it is not a candidate.
     */
    private function urlFor(Route $route): ?string
    {
        if (! in_array('GET', $route->methods(), true)) {
            return null;
        }

        // A parameterised route has no single URL to precache — `/posts/{post}` is a
        // template, not a page. Those belong in `pages.urls` with real values, or are
        // left to runtime caching as they are visited.
        if (str_contains($route->uri(), '{')) {
            return null;
        }

        // A route bound to another host is not same-origin, and the manifest addresses
        // everything by path.
        if ($route->getDomain() !== null) {
            return null;
        }

        $name = (string) ($route->getName() ?? '');

        // Pwax's own endpoints are precached as themselves, not as pages.
        if (str_starts_with($name, 'pwax.')) {
            return null;
        }

        return '/' . ltrim($route->uri(), '/');
    }

    /**
     * The view names a route's action renders, read from its source.
     *
     * More than one is possible and all are returned — a controller that branches between
     * two views is still one URL, and the route is precached if any of them is selected.
     *
     * @return list<string>
     */
    private function viewsRenderedBy(Route $route): array
    {
        $source = $this->sourceOf($route);

        if ($source === null || ! preg_match_all(self::RENDER_CALL, $source, $matches)) {
            return [];
        }

        /** @var list<string> $views */
        $views = array_values(array_unique($matches[1]));

        return $views;
    }

    /**
     * The source of a route's action, whether it is a closure or a controller method.
     */
    private function sourceOf(Route $route): ?string
    {
        try {
            $action = $route->getAction('uses');

            if ($action instanceof Closure) {
                return $this->linesOf(new ReflectionFunction($action));
            }

            if (! is_string($action) || ! str_contains($action, '@')) {
                return null;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                return null;
            }

            return $this->linesOf(new ReflectionMethod($class, $method));
        } catch (Throwable) {
            // A route whose action cannot be reflected on is simply not discoverable.
            // Never a reason to fail the manifest build.
            return null;
        }
    }

    /**
     * The lines a reflected function or method occupies in its file.
     */
    private function linesOf(ReflectionFunction|ReflectionMethod $reflection): ?string
    {
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        if ($file === false || $start === false || $end === false || ! is_readable($file)) {
            return null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }
}
