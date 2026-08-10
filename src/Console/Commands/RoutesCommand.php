<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * List every Pwax-served route, or every route in the application.
 *
 * The application's own route table is the one the developer maintains, and the routes
 * Pwax adds itself are the ones they reach for when something does not match — a 404 on
 * `/__pwax__/manifest.json`, a `pwax-route-list` style answer for the package side.
 * Names are the common link between the two: tracing a route name to its URL is the
 * middle of most debugging sessions.
 *
 * `--all` includes the application's own routes too. The default is Pwax-only because
 * that is the version that fits on a phone screen and the version that belongs in a
 * CI check.
 */
class RoutesCommand extends Command
{
    protected $signature = 'pwax:routes
        {--all : Include the application routes, not just Pwax ones}';

    protected $description = 'List every Pwax-served route with its method, name and URL';

    public function handle(Router $router): int
    {
        $routes = $this->collect($router);

        if ($routes === []) {
            $this->components->warn(
                $this->option('all')
                    ? 'No routes are registered.'
                    : 'No Pwax routes are registered. Did you disable pwax.routes.register?'
            );

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($routes as $route) {
            $rows[] = [
                'method' => implode('|', $route->methods()),
                'uri' => '/' . ltrim($route->uri(), '/'),
                'name' => $route->getName() ?? '',
                'action' => $this->action($route),
            ];
        }

        $this->components->info(sprintf('%d route(s)', count($rows)));

        $this->table(['Method', 'URI', 'Name', 'Action'], $rows);

        return self::SUCCESS;
    }

    /**
     * Filter the application's routes to Pwax ones, or include them all.
     *
     * @return list<Route>
     */
    private function collect(Router $router): array
    {
        $all = $router->getRoutes()->getRoutes();

        if ($this->option('all')) {
            return array_values($all);
        }

        return array_values(array_filter($all, static fn (Route $route): bool => str_starts_with(
            (string) $route->getName(),
            'pwax.'
        )));
    }

    /**
     * A short label for the controller action, or the closure line when one is bound.
     */
    private function action(Route $route): string
    {
        $action = $route->getActionName();

        if ($action !== '') {
            return $action;
        }

        $uses = $route->getAction('uses');

        return is_object($uses) ? $uses::class : 'Closure';
    }
}
