<?php

namespace Mxent\Pwax\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Mxent\Pwax\Data\Component compile(string $view, array<string, mixed> $data = [])
 * @method static \Mxent\Pwax\Http\Responses\ComponentResponse render(string $view, array<string, mixed> $data = [])
 * @method static string id(string $view)
 * @method static string resolve(string $id)
 * @method static string url(string $view, bool $absolute = false)
 * @method static string route(string $name, array<array-key, mixed>|string $parameters = [], bool $absolute = false)
 * @method static string homeUrl()
 * @method static array<string, mixed> payload(\Mxent\Pwax\Data\Component $component, bool $addressable = true)
 * @method static string import(?string $expression)
 * @method static bool wantsComponent(\Illuminate\Http\Request $request)
 * @method static string shell()
 *
 * @see \Mxent\Pwax\Pwax
 */
class Pwax extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Mxent\Pwax\Pwax::class;
    }
}
