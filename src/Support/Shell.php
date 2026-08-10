<?php

namespace Mxent\Pwax\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Mxent\Pwax\Data\Component;
use Mxent\Pwax\Pwax;
use Throwable;

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
    /** The bundle's digest once resolved; `false` until then, `null` if unreadable. */
    private string|false|null $runtimeVersion = false;

    public function __construct(
        private readonly Config $config,
        private readonly Pwax $pwax,
        private readonly ViewFactory $views,
        private readonly ?Request $request = null,
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
                ? (string) $this->config->get('pwax.service_worker.path', '/sw.js')
                : null,
            'serviceWorkerScope' => (string) $this->config->get('pwax.service_worker.scope', '/'),
            'csrf' => $this->csrfToken(),
            'home' => $this->pwax->homeUrl(),
            'progress' => $this->progress(),
            'transition' => (string) $this->config->get('pwax.transition.name', 'pwax-page'),
            'plugins' => $this->extensions('pwax.plugins'),
            'directives' => $this->extensions('pwax.directives'),
            'middleware' => $this->extensions('pwax.middleware_js'),
            'templates' => $this->templates(),
        ];
    }

    /**
     * Settings for the navigation progress bar, or `false` when it is switched off.
     *
     * `false` rather than an empty array, because the runtime treats it as a signal to
     * build nothing at all — no element, no timers. Colour and height belong to the
     * stylesheet in the head and are not repeated here.
     *
     * @return array{delay: int, trickle: bool}|false
     */
    private function progress(): array|false
    {
        if (! $this->config->get('pwax.progress.enabled', true)) {
            return false;
        }

        return [
            'delay' => (int) $this->config->get('pwax.progress.delay', 250),
            'trickle' => (bool) $this->config->get('pwax.progress.trickle', true),
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
        $tags = $this->frameworkScripts();

        foreach ((array) $this->config->get('pwax.scripts', []) as $script) {
            $tags[] = is_array($script) ? $script : ['src' => (string) $script];
        }

        $tags[] = ['src' => $this->runtimeUrl()];

        return $tags;
    }

    /**
     * Vue, Vue Router and Pinia alone — without the application's own extra scripts.
     *
     * The distinction matters to the service worker. These three and the runtime are the
     * application: an install that could not fetch them has produced something that will
     * not start, and should fail rather than pretend. An analytics tag someone added to
     * `pwax.scripts` is not in that category and must never be able to fail an install.
     *
     * @return list<array<string, string|bool>>
     */
    public function frameworkScripts(): array
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

        return $tags;
    }

    /**
     * The URL the client runtime bundle is served from, fingerprinted by its contents.
     *
     * The bundle is served `immutable`, which tells a browser not to revalidate for a
     * year — not even conditionally, so the ETag on it is never consulted. Without a
     * fingerprint the URL is identical from one package version to the next, and a
     * returning visitor keeps the runtime they first downloaded until the year is up or
     * they hard-reload.
     *
     * That is invisible when the service worker is on, because it precaches by content
     * hash and refetches with `cache: 'reload'`. It is not invisible with the worker off,
     * which is the default.
     *
     * The manifest builder asks for this same URL, so the precache key and the script tag
     * cannot disagree.
     */
    public function runtimeUrl(): string
    {
        $url = $this->pwax->route('pwax.runtime');
        $version = $this->runtimeVersion();

        return $version === null ? $url : $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
    }

    /**
     * A short digest of the shipped bundle, or null if it is not readable.
     *
     * Its contents rather than the package version: a version is what someone remembered
     * to bump, and this has to be right when they did not.
     */
    private function runtimeVersion(): ?string
    {
        if ($this->runtimeVersion !== false) {
            return $this->runtimeVersion;
        }

        $path = dirname(__DIR__, 2) . '/dist/pwax.js';
        $hash = is_file($path) ? @hash_file('xxh128', $path) : false;

        return $this->runtimeVersion = $hash === false ? null : substr($hash, 0, 12);
    }

    /**
     * `<link rel="preload">` attributes for the vendor scripts.
     *
     * The scripts themselves have to stay render-blocking and in order — Vue Router and
     * Pinia read the global `Vue` as they evaluate — but the browser has no reason to
     * discover them one at a time. Preloading starts all of them from the head.
     *
     * `integrity` and `crossorigin` are carried across deliberately: a preload whose
     * credentials mode differs from the eventual script fetch is not reused, and the
     * browser downloads the file twice.
     *
     * @return list<array<string, string|bool>>
     */
    public function vendorPreloads(): array
    {
        $preloads = [];

        foreach ($this->vendorScripts() as $script) {
            $src = $script['src'] ?? null;

            if (! is_string($src) || $src === '') {
                continue;
            }

            $attributes = ['href' => $src];

            foreach (['integrity', 'crossorigin'] as $name) {
                if (isset($script[$name])) {
                    $attributes[$name] = $script[$name];
                }
            }

            $preloads[] = $attributes;
        }

        return $preloads;
    }

    /**
     * URLs to hint with `<link rel="modulepreload">` for this page.
     *
     * Everything the first render will import, named in the head so the browser can start
     * fetching it immediately rather than discovering it three steps later.
     *
     * Two sources, both of which the server already knows and neither of which the browser
     * can see in time:
     *
     *   - The components this page imports. `Pwax::import()` compiles `@pwaxImport` into a
     *     `window.pwax.component('/__pwax__/c/….js')` call inside the page's script, so the
     *     URLs are sitting in the payload — but nothing asks for them until Vue has been
     *     downloaded, parsed, and has compiled and rendered the template. That is a whole
     *     serial round trip after the framework is already up, on a request the server
     *     could have named before it sent the document.
     *   - Configured plugins and directives, which `boot()` awaits *before* mounting. They
     *     are on the critical path by construction and had no resource hint at all.
     *
     * A hint, not a load: an unused `modulepreload` costs a warning in the console and
     * nothing else, and every URL here is one the page is about to ask for anyway.
     *
     * @return list<string>
     */
    public function modulePreloads(?Component $component = null): array
    {
        $urls = [];

        foreach (['pwax.plugins', 'pwax.directives'] as $key) {
            foreach ($this->extensions($key) as $entry) {
                if (($entry['type'] ?? '') === 'module' && isset($entry['url'])) {
                    $urls[] = $entry['url'];
                }
            }
        }

        if ($component !== null) {
            foreach ($this->importedModules($component) as $url) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The component module URLs a compiled script imports.
     *
     * Read back out of the emitted JavaScript rather than tracked while compiling: the
     * import is resolved at render time, by a Blade directive that can appear anywhere in
     * the view, so the script is the only place the full list actually exists.
     *
     * The call this matches is the one `Pwax::import()` writes, with a JSON-encoded URL —
     * so the quoting is known and there is no need to guess at the route prefix. A hand-
     * written call in someone's own `<script>` matches too, which is correct: it is still
     * a module the page is about to import.
     *
     * @return list<string>
     */
    private function importedModules(Component $component): array
    {
        if (! str_contains($component->script, 'window.pwax.component')) {
            return [];
        }

        $found = preg_match_all(
            '#window\.pwax\.component\(\s*"((?:[^"\\\\]|\\\\.)*)"#',
            $component->script,
            $matches
        );

        if ($found === false || $found === 0) {
            return [];
        }

        $urls = [];

        foreach ($matches[1] as $encoded) {
            $url = json_decode('"' . $encoded . '"');

            // Same-origin paths only. A preload for an absolute off-site URL needs a
            // `crossorigin` that this cannot know, and one that disagrees with the eventual
            // fetch makes the browser download the file twice instead of none.
            if (is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The document's text direction, for `<html dir>`.
     *
     * Taken from `pwax.manifest.dir`, which the web app manifest already needs, so an
     * application declares this once rather than in two places that can disagree. `auto`
     * is the default and usually the right answer: the browser reads the first strong
     * character and decides, which handles a mixed-locale application without a config
     * change per locale.
     */
    public function direction(): string
    {
        $dir = strtolower(trim((string) $this->config->get('pwax.manifest.dir', 'auto')));

        return in_array($dir, ['ltr', 'rtl', 'auto'], true) ? $dir : 'auto';
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
     * The CSRF token for this request, or null when there is no session.
     *
     * The offline shell is rendered outside the `web` group precisely so that what gets
     * precached has no session-bound token in it — a token cached on disk and replayed
     * days later is worthless at best. `csrf_token()` throws in that situation, so the
     * absence has to be a supported answer rather than an error.
     *
     * A shell served without a token still works: the runtime simply sends no CSRF
     * header, and the first write it attempts comes back 419, which it already handles by
     * reloading the page to pick up a fresh one.
     */
    public function csrfToken(): ?string
    {
        try {
            if ($this->request === null || ! $this->request->hasSession()) {
                return null;
            }

            $session = $this->request->session();

            return $session->isStarted() ? $session->token() : null;
        } catch (Throwable) {
            // A request with no session store raises rather than answering. That is the
            // ordinary case here, not an error.
            return null;
        }
    }

    /**
     * A copy of this shell with no request behind it.
     *
     * Used when rendering the shell for something other than serving it to a visitor —
     * hashing it for the asset manifest, above all. Rendered against the ambient request
     * it would pick up that visitor's CSRF token, and a manifest containing that is a
     * manifest that differs per person: every visitor would get their own cache name and
     * re-download the whole application.
     */
    public function withoutRequest(): self
    {
        return new self($this->config, $this->pwax, $this->views, null);
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
     * A value of `@pwaxImport('view.name')` (or `module:view.name`) becomes a component module
     * for the runtime to import. Anything else is treated as a dotted path to look up on
     * `window` — never as code to evaluate.
     *
     * @return array<string, array<string, string>>
     */
    private function extensions(string $key): array
    {
        // Whatever the directive is called in this application, so a config value reads
        // the same way as the views do.
        $directive = (string) $this->config->get('pwax.components.directive', 'pwaxImport');
        $pattern = '/^@' . preg_quote($directive, '/') . '\s*\(\s*[\'"]?(.+?)[\'"]?\s*\)$/';

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

        return ['type' => 'module', 'url' => $this->pwax->url($view), 'export' => $export];
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
