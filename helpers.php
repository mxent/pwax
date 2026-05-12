<?php

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MatthiasMullie\Minify;

if (! function_exists('vueParseTemplate')) {
    /**
     * Extract the contents of the outermost <template>...</template> block
     * from the rendered Blade view contents. Returns the inner HTML.
     *
     * @param  string  $template
     */
    function vueParseTemplate($template): string
    {
        if (! is_string($template) || $template === '' || strpos($template, '<template>') === false) {
            return '';
        }

        $start = strpos($template, '<template>');
        $end = strrpos($template, '</template>');

        if ($start === false || $end === false || $end <= $start) {
            return '';
        }

        $open = strlen('<template>');
        $content = substr($template, $start + $open, $end - ($start + $open));

        return $content !== false ? $content : '';
    }
}

if (! function_exists('pwaxExtractBlocks')) {
    /**
     * Extract all matches for a tag (e.g. script/style) from a string,
     * tolerating arbitrary attributes. Ignores tags with src/href attributes.
     *
     * @return array<int, string>
     */
    function pwaxExtractBlocks(string $tag, string $contents): array
    {
        $blocks = [];
        $pattern = '/<' . preg_quote($tag, '/') . '\b(?![^>]*\bsrc=)(?![^>]*\bhref=)[^>]*>(.*?)<\/' . preg_quote($tag, '/') . '>/is';
        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $m) {
                if (trim($m) !== '') {
                    $blocks[] = $m;
                }
            }
        }
        return $blocks;
    }
}

if (! function_exists('pwaxMinifyJs')) {
    /**
     * Safely minify JavaScript. Falls back to the original source on failure.
     */
    function pwaxMinifyJs(string $js): string
    {
        if (trim($js) === '') {
            return '';
        }
        try {
            $minifier = new Minify\JS($js);
            return $minifier->minify();
        } catch (\Throwable $e) {
            if (function_exists('app') && app()->bound('log')) {
                Log::warning('pwax: JS minification failed, returning raw source', [
                    'error' => $e->getMessage(),
                ]);
            }
            return $js;
        }
    }
}

if (! function_exists('pwaxMinifyCss')) {
    /**
     * Safely minify CSS. Falls back to the original source on failure.
     */
    function pwaxMinifyCss(string $css): string
    {
        if (trim($css) === '') {
            return '';
        }
        try {
            $minifier = new Minify\CSS($css);
            return $minifier->minify();
        } catch (\Throwable $e) {
            if (function_exists('app') && app()->bound('log')) {
                Log::warning('pwax: CSS minification failed, returning raw source', [
                    'error' => $e->getMessage(),
                ]);
            }
            return $css;
        }
    }
}

if (! function_exists('pwaxValidateViewName')) {
    /**
     * Validate a Blade view name. Throws InvalidArgumentException for unsafe input.
     */
    function pwaxValidateViewName(string $name): string
    {
        if ($name === '' || ! preg_match('/^[a-zA-Z0-9._:-]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid view name format');
        }
        if (strpos($name, '..') !== false) {
            throw new \InvalidArgumentException('Invalid view name: path traversal detected');
        }
        return $name;
    }
}

if (! function_exists('vue')) {
    /**
     * Render a Vue component from a Blade view that contains
     * <template>, <script>, and <style> blocks.
     *
     * @param  string  $blade
     * @param  array|null  $compact
     * @param  array  $config  Supported keys: bypass(bool), arr(bool)
     */
    function vue($blade, $compact = null, $config = []): JsonResponse|RedirectResponse|array|View
    {
        $bypass = $config['bypass'] ?? false;
        $request = function_exists('request') ? request() : null;

        // On a fresh (non-AJAX) page load, return the SPA shell.
        if (! $bypass && $request && ! $request->ajax() && ! $request->header('X-Pwa-Component')) {
            return view('pwax::components.vue.page');
        }

        try {
            pwaxValidateViewName((string) $blade);

            $viewContents = $compact
                ? view($blade, $compact)->render()
                : view($blade)->render();

            $template = vueParseTemplate($viewContents);

            $script = pwaxMinifyJs(implode("\n;\n", pwaxExtractBlocks('script', $viewContents)));
            $style = pwaxMinifyCss(implode("\n", pwaxExtractBlocks('style', $viewContents)));

            preg_match_all('/<script[^>]*?\bsrc=["\']([^"\']+)["\'][^>]*>\s*<\/script>/is', $viewContents, $m1);
            $scripts = $m1[1] ?? [];

            preg_match_all('/<link[^>]+\bhref=["\']([^"\']+)["\'][^>]*>/i', $viewContents, $m2);
            $styles = $m2[1] ?? [];

            $arr = [
                'style' => $style,
                'styles' => $styles,
                'template' => $template,
                'script' => $script,
                'scripts' => $scripts,
            ];

            if (! empty($config['arr'])) {
                return $arr;
            }

            $response = response()->json($arr);
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
            return $response;
        } catch (\InvalidArgumentException $e) {
            if (function_exists('app') && app()->bound('log')) {
                Log::warning('pwax: invalid view name', [
                    'blade' => $blade,
                    'error' => $e->getMessage(),
                ]);
            }
            return response()->json([
                'error' => 'Invalid component',
                'style' => '',
                'styles' => [],
                'template' => '<div class="pwax-error">Invalid component</div>',
                'script' => '',
                'scripts' => [],
            ], 400);
        } catch (\Throwable $e) {
            if (function_exists('app') && app()->bound('log')) {
                Log::error('pwax: error loading Vue component', [
                    'blade' => $blade,
                    'error' => $e->getMessage(),
                ]);
            }
            return response()->json([
                'error' => 'Failed to load component',
                'style' => '',
                'styles' => [],
                'template' => '<div class="pwax-error">Error loading component</div>',
                'script' => '',
                'scripts' => [],
            ], 500);
        }
    }
}

if (! function_exists('router')) {
    /**
     * Resolve a Laravel named route to a path/URL suitable for Vue Router.
     *
     * @param  string  $name
     * @param  array|string  $parameters
     * @param  bool  $absolute  When true, returns the absolute URL.
     */
    function router($name, $parameters = [], $absolute = false): string
    {
        if (! function_exists('app')) {
            return '/';
        }

        $app = app();
        if (! method_exists($app, 'bound') || ! $app->bound('url') || ! $app->bound('router')) {
            return '/';
        }

        try {
            $route = route($name, $parameters, true);
        } catch (\Throwable $e) {
            try {
                $route = route(config('pwax.home', 'index'), [], true);
            } catch (\Throwable $e2) {
                return '/';
            }
        }

        if ($absolute) {
            return $route;
        }

        $path = parse_url($route, PHP_URL_PATH);
        return $path ?: '/';
    }
}

if (! function_exists('import')) {
    /**
     * Render a JavaScript expression that lazily imports a Pwax component.
     *
     * Accepts forms:
     *   import('components.modal')
     *   import('MyModal from components.modal')
     *
     * @param  string  $ins
     */
    function import($ins): string
    {
        $ins = trim((string) $ins, " \"'");

        [$var, $blade] = str_contains($ins, ' from ')
            ? array_map('trim', explode(' from ', $ins, 2))
            : [null, $ins];

        $pascal = Str::studly(preg_replace('/[^a-zA-Z0-9]/', ' ', (string) $blade));
        $encoded = str_replace(['.', '::'], ['_x_', '__x__'], (string) $blade);
        $route = router('pwax.module', $encoded);

        return sprintf(
            'await window.pwaxImport(%s, %s, %s)',
            json_encode($route),
            json_encode($pascal),
            json_encode($var ?: '')
        );
    }
}
