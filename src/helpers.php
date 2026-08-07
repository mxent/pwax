<?php

use Mxent\Pwax\Http\Responses\ComponentResponse;
use Mxent\Pwax\Pwax;

/*
|--------------------------------------------------------------------------
| Pwax helpers
|--------------------------------------------------------------------------
|
| These are thin wrappers around the `Pwax` facade, kept prefixed because a
| package has no business claiming names as generic as `vue()` or `router()` in
| the global namespace. The 1.x names are still available — see
| `pwax.helpers.global` in the config file and the upgrade guide.
|
*/

if (! function_exists('pwax')) {
    /**
     * Resolve the Pwax service, or render a component when given a view name.
     *
     * @param  array<string, mixed>  $data
     */
    function pwax(?string $view = null, array $data = []): Pwax|ComponentResponse
    {
        /** @var Pwax $pwax */
        $pwax = app(Pwax::class);

        return $view === null ? $pwax : $pwax->render($view, $data);
    }
}

if (! function_exists('pwax_component')) {
    /**
     * Render a Blade view as a Vue component.
     *
     * Returns the SPA shell with the component embedded on a full page load, and the
     * JSON payload when the request comes from the Pwax client runtime.
     *
     * @param  array<string, mixed>  $data
     */
    function pwax_component(string $view, array $data = []): ComponentResponse
    {
        return app(Pwax::class)->render($view, $data);
    }
}

if (! function_exists('pwax_route')) {
    /**
     * Resolve a named Laravel route to a path Vue Router can navigate to.
     *
     * @param  array<array-key, mixed>|string  $parameters
     */
    function pwax_route(string $name, array|string $parameters = [], bool $absolute = false): string
    {
        return app(Pwax::class)->route($name, $parameters, $absolute);
    }
}
