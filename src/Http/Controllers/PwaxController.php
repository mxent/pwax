<?php

namespace Mxent\Pwax\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use MatthiasMullie\Minify;

class PwaxController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct() {}

    /**
     * Return the js file of the given blade file
     *
     * @param  string  $name
     */
    public function js($name): Response
    {
        $name = str_replace('_x_', '.', str_replace('__x__', '::', $name));
        $viewContents = view($name)->render();
        preg_match('/<script>(.*?)<\/script>/s', $viewContents, $matches);
        $script = isset($matches[1]) ? $matches[1] : '';
        $script = new Minify\JS($script);
        $script = $script->minify();

        return response($script)->header('Content-Type', 'application/javascript');
    }

    /**
     * Return the css file of the given blade file
     *
     * @param  string  $name
     */
    public function css($name): Response
    {
        $name = str_replace('_x_', '.', str_replace('__x__', '::', $name));
        $viewContents = view($name)->render();
        preg_match('/<style>(.*?)<\/style>/s', $viewContents, $matches);
        $style = isset($matches[1]) ? $matches[1] : '';
        $style = new Minify\CSS($style);
        $style = $style->minify();

        return response($style)->header('Content-Type', 'text/css');
    }

    /**
     * Return the vue file of the given blade file
     *
     * @param  string  $name
     */
    public function module($name): JsonResponse|Response
    {
        $name = str_replace('_x_', '.', str_replace('__x__', '::', $name));

        return vue($name, null, ['bypass' => true]);
    }
}
