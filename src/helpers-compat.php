<?php

use Mxent\Pwax\Http\Responses\ComponentResponse;
use Mxent\Pwax\Pwax;

/*
|--------------------------------------------------------------------------
| 1.x compatibility helpers
|--------------------------------------------------------------------------
|
| Loaded only when `pwax.helpers.global` is true. These names are extremely
| generic and collide easily with application code and other packages, which is
| why they are opt-in in 2.0 rather than always defined.
|
| Prefer `pwax_component()` / `pwax_route()`, or the `Pwax` facade.
|
*/

if (! function_exists('vue')) {
    /**
     * @deprecated 2.0 Use pwax_component() or Pwax::render().
     *
     * @param  array<string, mixed>|null  $data
     * @param  array{bypass?: bool, arr?: bool}  $config
     * @return ComponentResponse|array<string, mixed>
     */
    function vue(string $blade, ?array $data = null, array $config = []): ComponentResponse|array
    {
        /** @var Pwax $pwax */
        $pwax = app(Pwax::class);

        $response = $pwax->render($blade, $data ?? []);

        if (! empty($config['arr'])) {
            return $response->toArray();
        }

        // 1.x used `bypass` to mean "always return the payload, never the shell".
        if (! empty($config['bypass'])) {
            $response->asJson();
        }

        return $response;
    }
}

if (! function_exists('router')) {
    /**
     * @deprecated 2.0 Use pwax_route() or Pwax::route().
     *
     * @param  array<array-key, mixed>|string  $parameters
     */
    function router(string $name, array|string $parameters = [], bool $absolute = false): string
    {
        return app(Pwax::class)->route($name, $parameters, $absolute);
    }
}
