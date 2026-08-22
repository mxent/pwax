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
    /** The bundle's digest once resolved; `?string` covers both "unreadable" and "haven't checked yet". */
    private ?string $runtimeVersion = null;

    /** The resolved CSP nonce. Null is a legitimate answer, so the flag below is what says "resolved". */
    private ?string $nonce = null;

    private bool $nonceResolved = false;

    public function __construct(
        private readonly Config $config,
        private readonly Pwax $pwax,
        private readonly ViewFactory $views,
        private readonly ?Request $request = null,
        private readonly ?RenderFunctionStore $renderFunctions = null,
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
            // Distinct from `prefix` above, which is the route prefix. This one names the
            // worker's caches, so the page-side queue and the worker agree on where
            // requests waiting for a connection are kept.
            'cachePrefix' => (string) $this->config->get('pwax.service_worker.cache_name', 'pwax'),
            'push' => [
                'publicKey' => $this->config->get('pwax.push.public_key') ?: null,
                'endpoint' => $this->config->get('pwax.push.endpoint') ?: null,
            ],
            'csrf' => $this->csrfToken(),
            'home' => $this->pwax->homeUrl(),
            'progress' => $this->progress(),
            'prefetch' => $this->prefetch(),
            'plugins' => $this->extensions('pwax.vue.plugins'),
            'directives' => $this->extensions('pwax.vue.directives'),
            'middleware' => $this->extensions('pwax.vue.middleware'),
            'templates' => $this->templates(),
            // The id of the JSON island that carries a prerendered page's resolved state,
            // so the client runtime can find it for hydration. A constant rather than a
            // per-response value: the island id is the same for every page, and putting it
            // here keeps the runtime bundle static. The per-response `hydrate` flag lives
            // in the `pwax-initial` island instead, since it varies by route.
            'stateIslandId' => 'pwax-state',
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
     * Prefetching settings, or `false` when it is switched off.
     *
     * @return array{mode: string, delay: int}|false
     */
    private function prefetch(): array|false
    {
        $mode = $this->config->get('pwax.prefetch.mode', 'hover');

        // Anything outside this set means "off" — `false`, `null`, `'off'`, and any
        // unrecognised string all fall through to the same branch.
        if (! is_string($mode) || ! in_array($mode, ['hover', 'visible', 'load'], true)) {
            return false;
        }

        return [
            'mode' => $mode,
            'delay' => (int) $this->config->get('pwax.prefetch.delay', 65),
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
     * Application scripts that asked for the head are not here — see {@see headScripts()}.
     *
     * @return list<array<string, string|bool>>
     */
    public function vendorScripts(): array
    {
        $tags = $this->frameworkScripts();

        foreach ($this->configuredScripts(head: false) as $script) {
            $tags[] = $script;
        }

        $tags[] = ['src' => $this->runtimeUrl()];

        return $tags;
    }

    /**
     * Application scripts that asked to be in `<head>` rather than at the end of `<body>`.
     *
     *     'scripts' => [
     *         ['src' => '/js/theme.js', 'head' => true],
     *     ],
     *
     * The default position is the end of the body, behind Vue, Vue Router and Pinia, and
     * that is right for almost everything: a script there cannot block the first paint.
     *
     * Two kinds of script cannot live there, and both are ordinary:
     *
     *   - A CSS engine that runs in the browser — the Tailwind Play CDN and its like
     *     generate a stylesheet by scanning the DOM after they load. Behind the framework,
     *     the page paints unstyled and is restyled a moment later; on a prerendered page,
     *     which has its markup in the document from the first byte, that flash is the whole
     *     content of the page.
     *   - A script that has to run before the first paint to prevent a flash of its own —
     *     reading a stored theme and setting a class on `<html>` is the usual one.
     *
     * `pwax.blade.head` could always do this, but it costs a Blade view for what is one
     * tag. Being in the head means being render-blocking, which is the point and also the
     * cost: everything here delays the first paint, so put nothing here that does not have
     * to be.
     *
     * @return list<array<string, string|bool>>
     */
    public function headScripts(): array
    {
        return $this->configuredScripts(head: true);
    }

    /**
     * Every script the application configured, wherever it goes.
     *
     * The service worker precaches from this rather than from the two positional lists,
     * so moving a script into the head cannot quietly drop it from the offline install.
     *
     * @return list<array<string, string|bool>>
     */
    public function applicationScripts(): array
    {
        return [...$this->configuredScripts(head: true), ...$this->configuredScripts(head: false)];
    }

    /**
     * `pwax.scripts`, normalised and split by where the tag goes.
     *
     * `head` is a placement instruction, not an attribute, so it is stripped here — left in
     * place, `attributes()` would render it as a boolean attribute and every one of these
     * tags would carry a stray `head` in the markup.
     *
     * @return list<array<string, string|bool>>
     */
    private function configuredScripts(bool $head): array
    {
        $tags = [];

        foreach ((array) $this->config->get('pwax.scripts', []) as $script) {
            /** @var array<string, string|bool> $tag */
            $tag = is_array($script) ? $script : ['src' => (string) $script];

            if ((bool) ($tag['head'] ?? false) !== $head) {
                continue;
            }

            unset($tag['head']);

            $tags[] = $tag;
        }

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
        $strategy = $this->assetSource();
        /** @var array<string, string> $versions */
        $versions = $this->config->get('pwax.assets.versions', []);

        $files = [
            'vue' => $this->vueBuild(),
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
     * Which Vue build to serve.
     *
     * The full build by default, because compiling templates in the browser is the whole
     * premise of the package and the runtime-only build has no compiler.
     *
     * `assets.vue_build => 'runtime'` opts into the smaller one — 40.7 kB gzipped against
     * 60.8 kB — which is only safe once `php artisan pwax:compile` has produced a render
     * function for every template. This is the safety net for when it has not: an empty or
     * missing store serves the full build for that request. Slower than intended, never
     * broken. `pwax:doctor` reports it as an error, because a silent fallback that nobody
     * notices is a performance regression nobody can find.
     *
     * `isComplete()` is one cached boolean, not a per-component check — this runs on every
     * page render.
     */
    private function vueBuild(): string
    {
        return $this->renderFunctions?->active() === true
            ? 'vue.runtime.global.prod.js'
            : 'vue.global.prod.js';
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
        if ($this->runtimeVersion !== null) {
            return $this->runtimeVersion;
        }

        $path = dirname(__DIR__, 2) . '/dist/pwax.js';

        if (! is_file($path)) {
            return $this->runtimeVersion = null;
        }

        $hash = @hash_file('xxh128', $path);

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
     * The keys are `pwax.vue.*`, the same ones {@see runtimeConfig()} reads. They were
     * `pwax.plugins` and `pwax.directives` before 5.0 moved the group under `vue`, and this
     * method kept reading the old names — which no longer exist, so `extensions()` was
     * handed an empty array and the second of the two sources above quietly emitted
     * nothing. The failure is invisible from the outside: a missing resource hint costs a
     * round trip and never an error.
     *
     * @return list<string>
     */
    public function modulePreloads(?Component $component = null): array
    {
        $urls = [];

        foreach (['pwax.vue.plugins', 'pwax.vue.directives'] as $key) {
            foreach ($this->extensions($key) as $entry) {
                if (($entry['type'] ?? '') === 'module' && isset($entry['url'])) {
                    $urls[] = $entry['url'];
                }
            }
        }

        if ($component !== null) {
            foreach ($this->pwax->importedUrls($component->script) as $url) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Where the framework scripts come from: `local` or `cdn`.
     *
     * `assets.source` since 4.1, and the only key read since 5.0. It was `assets.strategy`,
     * which put a fifth key called "strategy" in a config where the other four choose a
     * caching behaviour and this one chooses a hostname. The fallback that kept the old
     * name working through 4.x is gone; `pwax:doctor` fails on a config that still sets it,
     * because an application would otherwise silently move from CDN to local on upgrade.
     */
    public function assetSource(): string
    {
        $source = $this->config->get('pwax.assets.source') ?? 'local';

        return strtolower(trim((string) $source)) === 'cdn' ? 'cdn' : 'local';
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
        return new self($this->config, $this->pwax, $this->views, null, $this->renderFunctions);
    }

    /**
     * The CSP nonce for inline blocks, if the application supplies one.
     *
     * Resolved once per shell. `pwax.csp.nonce` may be a callable, and one document asks
     * for the nonce from several places — the head partial, the foot partial, the shell's
     * own `<noscript>` block, and the runtime config the client stamps on the stylesheets
     * it attaches. A callable that mints a fresh value per call would give each of them a
     * different nonce, and a `Content-Security-Policy` header names exactly one: every
     * block but whichever the header happened to match would be refused.
     *
     * Memoised on the instance rather than statically: the manifest builder renders the
     * shell through `withoutRequest()`, and a process-wide memo would hand a visitor's
     * nonce to that build and from there into the manifest hash.
     */
    public function nonce(): ?string
    {
        if ($this->nonceResolved) {
            return $this->nonce;
        }

        $nonce = $this->config->get('pwax.csp.nonce');

        if (is_callable($nonce)) {
            $nonce = $nonce();
        }

        $this->nonceResolved = true;

        return $this->nonce = is_string($nonce) && $nonce !== '' ? $nonce : null;
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

        // By filename first, then by package. Vue ships two builds and Pwax serves either
        // of them, so a map keyed only on the package name would hand the full build's
        // hash to the runtime-only file — and the browser refuses a script whose digest
        // does not match, which is the whole app failing to start.
        $integrity = $hashes[$file] ?? $hashes[$package] ?? null;

        if (is_string($integrity) && $integrity !== '') {
            $attributes['integrity'] = $integrity;
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
