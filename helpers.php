<?php

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use MatthiasMullie\Minify;
use Illuminate\Support\Str;

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
    if (! request()->ajax() && ! $bypass && ! request()->header('X-Pwa-Component')) {
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

/**
 * Render a dynamic import for Vue components
 *
 * @param  string  $ins
 */
function import($ins)
{
    $ins = trim($ins);
    if (Str::startsWith($ins, '"') || Str::startsWith($ins, "'")) {
        $ins = substr($ins, 1);
    }
    if (Str::endsWith($ins, '"') || Str::endsWith($ins, "'")) {
        $ins = substr($ins, 0, -1);
    }
    $insBits = explode(' from ', $ins);
    $var = count($insBits) == 2 ? $insBits[0] : null;
    if ($var) {
        $ins = $insBits[1];
    }
    $ins = explode('/', $ins);
    $module = null;
    if (Str::startsWith($ins[0], '~')) {
        $ins[0] = Str::replaceFirst('~', '', $ins[0]);
        if (Str::startsWith($ins[0], '/')) {
            $ins[0] = Str::replaceFirst('/', '', $ins[0]);
        }
        $module = array_shift($ins);
    }
    $bladeBits = [];
    if ($module) {
        $bladeBits[] = $module;
    }
    $bladeBits[] = implode('.', $ins);
    $blade = implode('::', $bladeBits);
    $pascal = preg_replace('/[^a-zA-Z0-9]/', ' ', $blade);
    $pascal = Str::studly($pascal);

    $script = 'window.pwaxImport("'.route('pwax.module', str_replace('.', '_x_', str_replace('::', '__x__', $blade))).'", "'.$pascal.'", "'.($var ? $var : '').'")';

    return $script;
}
