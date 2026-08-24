<?php

use Mxent\Pwax\Http\Responses\ComponentResponse;
use Mxent\Pwax\Pwax;

/*
|--------------------------------------------------------------------------
| Pwax helpers
|--------------------------------------------------------------------------
|
| Each helper is a thin wrapper around one method on the `Pwax` facade, and is
| named after it: `pwaxRender()` is `Pwax::render()`, `pwaxRoute()` is
| `Pwax::route()`, `pwaxImport()` is `Pwax::import()`. Knowing one spelling
| gives you the other, which is the whole point of the naming.
|
| They stay prefixed because a package has no business claiming names as
| generic as `vue()` or `router()` in the global namespace.
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

if (! function_exists('pwaxRender')) {
    /**
     * Render a Blade view as a Vue component.
     *
     * Returns the SPA shell with the component embedded on a full page load, and the
     * JSON payload when the request comes from the Pwax client runtime.
     *
     *     Route::get('/', fn () => pwaxRender('pages.home', ['posts' => $posts]));
     *
     * @param  array<string, mixed>  $data
     */
    function pwaxRender(string $view, array $data = []): ComponentResponse
    {
        return app(Pwax::class)->render($view, $data);
    }
}

if (! function_exists('pwaxRoute')) {
    /**
     * Resolve a named Laravel route to a path Vue Router can navigate to.
     *
     * @param  array<array-key, mixed>|string  $parameters
     */
    function pwaxRoute(string $name, array|string $parameters = [], bool $absolute = false): string
    {
        return app(Pwax::class)->route($name, $parameters, $absolute);
    }
}

if (! function_exists('pwaxImport')) {
    /**
     * Compile a component reference into the JavaScript that imports it.
     *
     * This is what `@pwaxImport('components.modal')` expands to. Calling it directly is
     * for the cases Blade cannot reach — building a component map in PHP, say.
     */
    function pwaxImport(?string $expression): string
    {
        return app(Pwax::class)->import($expression);
    }
}
