<?php

namespace Mxent\Pwax;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mxent\Pwax\Compiler\ComponentCompiler;
use Mxent\Pwax\Data\Component;
use Mxent\Pwax\Exceptions\ComponentNotAllowed;
use Mxent\Pwax\Http\Responses\ComponentResponse;
use Mxent\Pwax\Support\ComponentId;
use Mxent\Pwax\Support\RenderFunctionStore;
use Throwable;

/**
 * The package's public API, resolvable as `Pwax::` or via the container.
 *
 * Everything the rest of the package and the host application need goes through here:
 * compiling components, minting and resolving their signed identifiers, building their
 * URLs, and deciding whether a given request wants the SPA shell or a JSON payload.
 */
class Pwax
{
    /**
     * Request header that marks a fetch as coming from the Pwax client runtime.
     */
    public const HEADER = 'X-Pwax-Component';

    /**
     * Response header carrying a URL the client must navigate to with a full page load.
     */
    public const LOCATION_HEADER = 'X-Pwax-Location';

    /**
     * Headers that change the shape of a response, and so must be declared in `Vary`.
     * Without this a shared cache will happily hand the JSON payload to a browser
     * navigation, or the HTML shell to the client runtime.
     */
    public const VARY = self::HEADER . ', X-Requested-With, Accept';

    public function __construct(
        private readonly ComponentCompiler $compiler,
        private readonly ComponentId $ids,
        private readonly Config $config,
        private readonly UrlGenerator $url,
        // Optional, and absent in the default configuration. Precompiling is opt-in and
        // an application that has not opted in never writes a store to read.
        private readonly ?RenderFunctionStore $renderFunctions = null,
    ) {}

    /**
     * Compile a Blade view into a component payload.
     *
     * Whether the result is worth storing follows from whether it was given data, and it
     * is the same distinction `payload()` draws below. A view compiled with no data is a
     * pure function of its name: every visitor produces the same bytes, so one cache
     * entry serves all of them forever. A view compiled *with* controller data is not —
     * its output is particular to this request, and since the compile cache is keyed on
     * that output, storing it writes an entry that will never be read again.
     *
     * Pass `$store` explicitly to override, which is how a page that has declared itself
     * visitor-independent through `ComponentResponse::cacheable()` opts back in.
     *
     * @param  array<string, mixed>  $data
     */
    public function compile(string $view, array $data = [], ?bool $store = null): Component
    {
        $this->assertAllowed($view);

        return $this->compiler->compile($view, $data, $store ?? $data === [])->withId($this->id($view));
    }

    /**
     * Build the response for a component-backed route.
     *
     * On a normal browser navigation this returns the SPA shell with the component
     * embedded, so the first paint needs no further round trips. On a request from the
     * client runtime it returns the JSON payload. Both carry a `Vary` header.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data = []): ComponentResponse
    {
        return new ComponentResponse($this, $view, $data);
    }

    /**
     * Mint the signed identifier a client uses to address a view.
     */
    public function id(string $view): string
    {
        return $this->ids->encode($view);
    }

    /**
     * Build the wire payload for a component.
     *
     * `module` is the component's URL as a real ES module, which the client can import
     * directly instead of building a URL out of a script string — so no `blob:` or
     * `data:` is needed in `script-src`, and the browser's HTTP cache applies.
     *
     * That only works for components that render the same for everyone. A page rendered
     * with controller data cannot be re-derived from its view name alone: requesting
     * `/__pwax__/c/{id}.js` would render the view with no data at all, and any
     * `@json($user)` in its script would be an undefined variable. Page payloads
     * therefore carry `script` inline, and `$addressable` is false for them.
     *
     * A precompiled render function rides along in that inline script rather than in a
     * field of its own. The client evaluates the script as a module, so a render function
     * inside it is executed by the module loader; handed over as a separate string it
     * would need `new Function` to become callable, which is the one thing precompiling
     * exists to avoid.
     *
     * @return array<string, mixed>
     */
    public function payload(Component $component, bool $addressable = true): array
    {
        $payload = $component->toArray();

        $payload['module'] = $addressable && $component->id !== null
            ? $this->route('pwax.js', $component->id)
            : null;

        if (! $addressable) {
            $bindings = $this->renderFunctions?->bindings($component->template);

            if ($bindings !== null) {
                $payload['script'] = trim($bindings . "\n" . (string) $payload['script']);
            }
        }

        return $payload;
    }

    /**
     * Resolve a signed identifier back to a Blade view name.
     *
     * @throws Exceptions\InvalidComponentId
     * @throws ComponentNotAllowed
     */
    public function resolve(string $id): string
    {
        $view = $this->ids->decode($id);

        $this->assertAllowed($view);

        return $view;
    }

    /**
     * Build the URL a component is served from.
     *
     * A component has one representation — an ES module carrying its template, script,
     * styles and scope together — so there is no format to choose.
     */
    public function url(string $view, bool $absolute = false): string
    {
        return $this->route('pwax.js', $this->id($view), $absolute);
    }

    /**
     * Resolve a named Laravel route to a path Vue Router can consume.
     *
     * An unknown route name is not silently swallowed. In debug mode it throws, because a
     * typo in a route name should fail loudly rather than quietly send every link to the
     * home page. In production it logs and falls back.
     *
     * @param  array<array-key, mixed>|string  $parameters
     */
    public function route(string $name, array|string $parameters = [], bool $absolute = false): string
    {
        try {
            $url = $this->url->route($name, $parameters, true);
        } catch (Throwable $e) {
            if ($this->config->get('app.debug')) {
                throw $e;
            }

            Log::warning('pwax: unknown route name, falling back to the home route.', [
                'name' => $name,
                'fallback' => $this->config->get('pwax.home'),
            ]);

            try {
                $url = $this->url->route((string) $this->config->get('pwax.home', 'index'), [], true);
            } catch (Throwable) {
                return '/';
            }
        }

        if ($absolute) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return ($path ?: '/') . ($query !== null && $query !== '' ? '?' . $query : '');
    }

    /**
     * The application's home URL, resolved defensively.
     *
     * Used by the bundled error and shell templates. Unlike `route()`, this never throws:
     * an application that has not defined a `home` route should still get a rendered
     * error page rather than a second, more confusing exception on top of the first.
     */
    public function homeUrl(): string
    {
        try {
            return $this->route((string) $this->config->get('pwax.home', 'index'));
        } catch (Throwable) {
            return '/';
        }
    }

    /**
     * Compile a `@pwaxImport` directive expression into the JavaScript that loads it.
     *
     * Accepts either `'components.modal'` or `'Modal from components.modal'`, the latter
     * selecting a named export instead of the default one.
     *
     * This runs at *request* time, not while Blade compiles the view. Resolving the URL
     * at compile time would bake it into `storage/framework/views`, so changing
     * `route_prefix` — or running `view:cache` before routes were available — would leave
     * permanently broken imports behind.
     *
     * The emitted call is synchronous and returns a Vue async component. Awaiting the
     * import at module top level instead would deadlock two components that reference each
     * other, each waiting for the other to finish evaluating. Deferring resolution to
     * render time removes the cycle.
     */
    public function import(?string $expression): string
    {
        $expression = trim((string) $expression, " \t\n\r\"'");

        if ($expression === '') {
            return '/* pwax: empty import */ null';
        }

        [$export, $view] = str_contains($expression, ' from ')
            ? array_map(trim(...), explode(' from ', $expression, 2))
            : ['', $expression];

        return sprintf(
            'window.pwax.component(%s, %s)',
            json_encode($this->url($view), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode($export, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * The component module URLs a compiled script imports.
     *
     * Read back out of the emitted JavaScript rather than tracked while compiling: the
     * import is resolved at render time, by a Blade directive that can appear anywhere in
     * the view, so the script is the only place the full list actually exists.
     *
     * The call this matches is the one {@see import()} writes, with a JSON-encoded URL —
     * so the quoting is known and there is no need to guess at the route prefix. A hand-
     * written call in someone's own `<script>` matches too, which is correct: it is still
     * a module the page is about to import.
     *
     * Same-origin paths only. The head emits a `modulepreload` for each, and a preload for
     * an absolute off-site URL needs a `crossorigin` that cannot be known here.
     *
     * @return list<string>
     */
    public function importedUrls(string $script): array
    {
        if (! str_contains($script, 'window.pwax.component')) {
            return [];
        }

        $found = preg_match_all(
            '#window\.pwax\.component\(\s*"((?:[^"\\\\]|\\\\.)*)"#',
            $script,
            $matches
        );

        if ($found === false || $found === 0) {
            return [];
        }

        $urls = [];

        foreach ($matches[1] as $encoded) {
            $url = json_decode('"' . $encoded . '"');

            // A preload for an absolute off-site URL needs a `crossorigin` that cannot be
            // known here, and one that disagrees with the eventual fetch makes the browser
            // download the file twice instead of none.
            if (is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Does this request want a JSON component payload rather than the SPA shell?
     */
    public function wantsComponent(Request $request): bool
    {
        return $request->hasHeader(self::HEADER);
    }

    /**
     * The Blade view used as the SPA shell on a full page load.
     */
    public function shell(): string
    {
        return (string) $this->config->get('pwax.shell') ?: 'pwax::layouts.shell';
    }

    /**
     * Enforce the optional `pwax.components.allowed` namespace list.
     *
     * Signing already prevents a client from naming a view the server never emitted;
     * this narrows the blast radius further if a signed identifier ever leaks.
     *
     * @throws ComponentNotAllowed
     */
    private function assertAllowed(string $view): void
    {
        /** @var list<string> $patterns */
        $patterns = $this->config->get('pwax.components.allowed', []);

        if ($patterns === []) {
            return;
        }

        // The package's own views must always resolve, whatever the application allows.
        if (str_starts_with($view, 'pwax::')) {
            return;
        }

        if (! Str::is($patterns, $view)) {
            throw ComponentNotAllowed::for($view);
        }
    }
}
