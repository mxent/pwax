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

    /**
     * Files already read, so a controller holding twenty routes is read once.
     *
     * @var array<string, list<string>|null>
     */
    private array $sources = [];

    public function __construct(
        private readonly Router $router,
        private readonly Config $config,
        private readonly ComponentRegistry $components,
    ) {}

    /**
     * Every discoverable page route, with the views its action can render.
     *
     * @return list<array{url: string, views: list<string>}>
     */
    public function all(): array
    {
        $pages = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $url = $this->urlFor($route);

            if ($url === null) {
                continue;
            }

            $views = $this->viewsRenderedBy($route);

            if ($views === []) {
                continue;
            }

            // Merged rather than replaced. Two routes can answer the same path — one
            // named, one not — and a controller that branches renders more than one view;
            // keeping all of them is what lets the selection below consider each.
            $pages[$url] = ['url' => $url, 'views' => array_values(array_unique(
                array_merge($pages[$url]['views'] ?? [], $views)
            ))];
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
     * @return list<array{url: string, views: list<string>}>
     */
    public function precachable(): array
    {
        if (! $this->config->get('pwax.service_worker.pages.discover', true)) {
            return [];
        }

        /** @var list<string> $views */
        $views = array_column($this->components->precachable(), 'view');

        if ($views === []) {
            return [];
        }

        $allowed = array_flip($views);

        // Any one selected view is enough. A controller that branches between an admin
        // view and a public one still answers a single URL, and precaching it stores
        // whichever the server renders for an anonymous request.
        return array_values(array_filter(
            $this->all(),
            static function (array $page) use ($allowed): bool {
                foreach ($page['views'] as $view) {
                    if (isset($allowed[$view])) {
                        return true;
                    }
                }

                return false;
            }
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

        if ($file === false || $start === false || $end === false) {
            return null;
        }

        $lines = $this->linesIn($file);

        if ($lines === null) {
            return null;
        }

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * A file's lines, read once per build.
     *
     * A single controller commonly holds every page route in an application, and reading
     * it once per route turns discovery into an O(routes × filesize) walk for no reason.
     *
     * @return list<string>|null
     */
    private function linesIn(string $file): ?array
    {
        if (array_key_exists($file, $this->sources)) {
            return $this->sources[$file];
        }

        if (! is_readable($file)) {
            return $this->sources[$file] = null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);

        return $this->sources[$file] = $lines === false ? null : $lines;
    }
}
