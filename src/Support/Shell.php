<?php

namespace Mxent\Pwax\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\HtmlString;
use Mxent\Pwax\Pwax;

/**
 * Assembles everything the SPA shell needs: vendor asset tags, the runtime
 * configuration island, and the customisable markup fragments.
 *
 * All of it is data. The client runtime is a static, cacheable bundle that reads one
 * JSON block; nothing here is interpolated into JavaScript. In 1.x the runtime was
 * assembled inside a Blade file with `{!! !!}`, which meant a stray quote in
 * `config/pwax.php` produced a syntax error that took down the whole page.
 */
class Shell
{
    public function __construct(
        private readonly Config $config,
        private readonly Pwax $pwax,
        private readonly ViewFactory $views,
    ) {}

    /**
     * The runtime configuration island, ready to embed.
     *
     * @return array<string, mixed>
     */
    public function runtimeConfig(): array
    {
        return [
            'prefix' => trim((string) $this->config->get('pwax.route_prefix', '__pwax__'), '/'),
            'hashRouting' => (bool) $this->config->get('pwax.hash_route', false),
            'base' => $this->basePath(),
            'mount' => 'pwax',
            'nonce' => $this->nonce(),
            'pinia' => (bool) $this->config->get('pwax.assets.pinia', true),
            'serviceWorker' => $this->config->get('pwax.service_worker.enabled', false)
                ? (string) $this->config->get('pwax.service_worker.path', '/service-worker.js')
                : null,
            'home' => $this->pwax->homeUrl(),
            'plugins' => $this->extensions('pwax.plugins'),
            'directives' => $this->extensions('pwax.directives'),
            'middleware' => $this->extensions('pwax.middleware_js'),
            'templates' => $this->templates(),
        ];
    }

    /**
     * Markup fragments the runtime renders, so they stay customisable through Blade
     * without the runtime bundle needing to be generated per application.
     *
     * @return array<string, string>
     */
    public function templates(): array
    {
        return [
            'content' => $this->render('pwax::components.content', 'pwax.blade.content'),
            'loader' => $this->render('pwax::components.loader', 'pwax.blade.loader'),
            'error' => $this->render('pwax::components.error', 'pwax.blade.error'),
        ];
    }

    /**
     * The vendor scripts, in the order they must execute.
     *
     * Vue Router and Pinia are IIFE builds that read the global `Vue`, so Vue has to be
     * evaluated first. None of them are modules, so they cannot be deferred.
     *
     * @return list<array<string, string|bool>>
     */
    public function vendorScripts(): array
    {
        $strategy = (string) $this->config->get('pwax.assets.strategy', 'local');
        /** @var array<string, string> $versions */
        $versions = $this->config->get('pwax.assets.versions', []);

        $files = [
            // The FULL Vue build. The runtime-only build has no template compiler, and
            // compiling templates in the browser is the whole premise of this package.
            'vue' => 'vue.global.prod.js',
            'vue-router' => 'vue-router.global.prod.js',
        ];

        if ($this->config->get('pwax.assets.pinia', true)) {
            $files['pinia'] = 'pinia.iife.prod.js';
        }

        $tags = [];

        foreach ($files as $package => $file) {
            $tags[] = $strategy === 'cdn'
                ? $this->cdnScript($package, $file, $versions[$package] ?? null)
                : ['src' => $this->localAsset($file, $versions[$package] ?? null)];
        }

        foreach ((array) $this->config->get('pwax.scripts', []) as $script) {
            $tags[] = is_array($script) ? $script : ['src' => (string) $script];
        }

        $tags[] = ['src' => $this->pwax->route('pwax.runtime')];

        return $tags;
    }

    /**
     * Extra stylesheets configured by the application.
     *
     * @return list<array<string, string|bool>>
     */
    public function stylesheets(): array
    {
        $styles = [];

        foreach ((array) $this->config->get('pwax.styles', []) as $style) {
            /** @var array<string, string|bool> $attributes */
            $attributes = is_array($style) ? $style : ['href' => (string) $style];

            $styles[] = $attributes;
        }

        return $styles;
    }

    /**
     * Render an attribute list, escaping every value.
     *
     * @param  array<string, string|bool|null>  $attributes
     */
    public function attributes(array $attributes): HtmlString
    {
        $parts = [];

        foreach ($attributes as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }

            $name = preg_replace('/[^A-Za-z0-9:_-]/', '', (string) $name) ?? '';

            if ($name === '') {
                continue;
            }

            $parts[] = $value === true
                ? $name
                : $name . '="' . e((string) $value) . '"';
        }

        return new HtmlString(implode(' ', $parts));
    }

    /**
     * The CSP nonce for inline blocks, if the application supplies one.
     */
    public function nonce(): ?string
    {
        $nonce = $this->config->get('pwax.csp.nonce');

        if (is_callable($nonce)) {
            $nonce = $nonce();
        }

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    /**
     * Turn configured plugin/directive/middleware entries into resolvable descriptors.
     *
     * A value of `@pwax('view.name')` (or `module:view.name`) becomes a component module
     * for the runtime to import. Anything else is treated as a dotted path to look up on
     * `window` — never as code to evaluate.
     *
     * @return array<string, array<string, string>>
     */
    private function extensions(string $key): array
    {
        $directive = (string) $this->config->get('pwax.components.directive', 'pwax');
        $pattern = '/^@(?:' . preg_quote($directive, '/') . '|import)\s*\(\s*[\'"]?(.+?)[\'"]?\s*\)$/';

        $resolved = [];

        foreach ((array) $this->config->get($key, []) as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);

            if (preg_match($pattern, $value, $m) === 1) {
                $resolved[(string) $name] = $this->moduleEntry($m[1]);

                continue;
            }

            if (str_starts_with($value, 'module:')) {
                $resolved[(string) $name] = $this->moduleEntry(substr($value, 7));

                continue;
            }

            $resolved[(string) $name] = ['type' => 'global', 'path' => $value];
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    private function moduleEntry(string $reference): array
    {
        [$export, $view] = str_contains($reference, ' from ')
            ? array_map(trim(...), explode(' from ', $reference, 2))
            : ['', trim($reference)];

        // `.js`, because the runtime resolves this with a dynamic `import()`.
        return ['type' => 'module', 'url' => $this->pwax->url($view, 'js'), 'export' => $export];
    }

    /**
     * @return array<string, string|bool>
     */
    private function cdnScript(string $package, string $file, ?string $version): array
    {
        $base = rtrim((string) $this->config->get('pwax.assets.cdn.base', 'https://cdn.jsdelivr.net/npm'), '/');
        $src = $base . '/' . $package . ($version ? '@' . $version : '') . '/dist/' . $file;

        /** @var array<string, string> $hashes */
        $hashes = $this->config->get('pwax.assets.cdn.integrity', []);

        $attributes = ['src' => $src];

        if (isset($hashes[$package])) {
            $attributes['integrity'] = $hashes[$package];
            $attributes['crossorigin'] = 'anonymous';
        }

        return $attributes;
    }

    private function localAsset(string $file, ?string $version): string
    {
        $path = '/' . trim((string) $this->config->get('pwax.assets.local_path', '/vendor/pwax'), '/') . '/' . $file;

        // Each package carries its own version so the query busts only the file that
        // actually changed — tagging every asset with Vue's version would invalidate
        // Pinia and Vue Router on a Vue patch release, and vice versa.
        return $version === null || $version === '' ? $path : $path . '?v=' . rawurlencode($version);
    }

    private function render(string $default, string $overrideKey): string
    {
        $view = (string) ($this->config->get($overrideKey) ?: $default);

        return trim($this->views->make($view)->render());
    }

    private function basePath(): string
    {
        $base = parse_url((string) $this->config->get('app.url', '/'), PHP_URL_PATH);

        return rtrim((string) ($base ?: ''), '/') . '/';
    }
}
