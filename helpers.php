<?php

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use MatthiasMullie\Minify;

/**
 * Parse the Vue template from the view contents
 *
 * @param  string  $template
 */
function vueParseTemplate($template): string
{

    if (strpos($template, '<template>') === false) {
        return '';
    }

    $start = strpos($template, '<template>');
    $end = strrpos($template, '</template>');

    if ($start === false || $end === false) {
        return $template;
    }

    $content = substr($template, $start + strlen('<template>'), $end - ($start + strlen('<template>')));
    $pattern = '/<template>(.*?)<\/template>/s';
    $content = preg_replace_callback($pattern, function ($matches) {
        return $matches[1];
    }, $content);

    return $content;
}

/**
 * Render a Vue component
 *
 * @param  string  $blade
 * @param  array  $compact
 * @param  array  $config
 */
function vue($blade, $compact = null, $config = []): JsonResponse|RedirectResponse|array|View
{

    $bypass = isset($config['bypass']) ? $config['bypass'] : false;
    if (! request()->ajax() && ! $bypass && ! request()->header('X-Vue-Component')) {
        return view('pwax::components.vue.page');
    }

    if ($compact) {
        $viewContents = view($blade, $compact)->render();
    } else {
        $viewContents = view($blade)->render();
    }

    $template = vueParseTemplate($viewContents);

    preg_match('/<script>(.*?)<\/script>/s', $viewContents, $matches);
    $script = isset($matches[1]) ? $matches[1] : '';
    $script = new Minify\JS($script);
    $script = $script->minify();

    preg_match('/<style>(.*?)<\/style>/s', $viewContents, $matches);
    $style = isset($matches[1]) ? $matches[1] : '';
    $style = new Minify\CSS($style);
    $style = $style->minify();

    preg_match_all('/<script[^>]*?src="(.*?)"[^>]*?(?:(?<!\/)>|\/>)/s', $viewContents, $matches);
    $scripts = $matches[1];

    preg_match_all('/<link[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $viewContents, $matches);
    $styles = $matches[1];

    $arr = [
        'style' => $style,
        'styles' => $styles,
        'template' => $template,
        'script' => $script,
        'scripts' => $scripts,
    ];

    $returnArr = isset($config['arr']) ? $config['arr'] : false;
    if ($returnArr) {
        return $arr;
    }

    return response()->json($arr);
}

/**
 * Get the route path and return it as a hash route
 *
 * @param  string  $name
 */
function router($name): string
{
    $route = route($name);
    $pathWithoutDomain = parse_url($route, PHP_URL_PATH);

    if (! $pathWithoutDomain) {
        $route = route(config('pwax.home', 'index'));
        $pathWithoutDomain = parse_url($route, PHP_URL_PATH);
    }

    return $pathWithoutDomain ?? '/';
}
