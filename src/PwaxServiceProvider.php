<?php

namespace Mxent\Pwax;

use Composer\InstalledVersions;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Mxent\Pwax\Compiler\BlockExtractor;
use Mxent\Pwax\Compiler\ComponentCompiler;
use Mxent\Pwax\Compiler\StyleScoper;
use Mxent\Pwax\Compiler\TemplateStamper;
use Mxent\Pwax\Console\Commands\ClearCommand;
use Mxent\Pwax\Console\Commands\CompileCommand;
use Mxent\Pwax\Console\Commands\ComponentMakeCommand;
use Mxent\Pwax\Console\Commands\DoctorCommand;
use Mxent\Pwax\Console\Commands\InstallCommand;
use Mxent\Pwax\Console\Commands\PrecacheCommand;
use Mxent\Pwax\Console\Commands\PushEndpointCommand;
use Mxent\Pwax\Console\Commands\RoutesCommand;
use Mxent\Pwax\Console\Commands\SkillCommand;
use Mxent\Pwax\Console\Commands\VapidCommand;
use Mxent\Pwax\Contracts\Minifier;
use Mxent\Pwax\Http\Middleware\HandlePwaxRequests;
use Mxent\Pwax\Minification\CachedMinifier;
use Mxent\Pwax\Minification\MatthiasMullieMinifier;
use Mxent\Pwax\Minification\NullMinifier;
use Mxent\Pwax\Pwa\AssetManifest;
use Mxent\Pwax\Pwa\ComponentRegistry;
use Mxent\Pwax\Pwa\HeadMeta;
use Mxent\Pwax\Pwa\PageRegistry;
use Mxent\Pwax\Pwa\PublicAssets;
use Mxent\Pwax\Pwa\ServiceWorker;
use Mxent\Pwax\Pwa\Ssr\Prerenderer;
use Mxent\Pwax\Pwa\WebManifest;
use Mxent\Pwax\Support\ComponentId;
use Mxent\Pwax\Support\RenderFunctionStore;
use Mxent\Pwax\Support\Shell;

class PwaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/pwax.php', 'pwax');

        $this->registerCompiler();
        $this->registerPwax();
        $this->registerPrerenderer();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ClearCommand::class,
                ComponentMakeCommand::class,
                DoctorCommand::class,
                PrecacheCommand::class,
                CompileCommand::class,
                VapidCommand::class,
                PushEndpointCommand::class,
                RoutesCommand::class,
                SkillCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'pwax');

        $this->bootDirectives();
        $this->bootMiddleware();
        $this->bootRoutes();
        $this->bootPublishing();
        $this->bootAbout();
    }

    /**
     * Report into `php artisan about`.
     *
     * The three questions someone debugging asks first — is the worker on, which build is
     * the manifest at, and where are components cached — answered without opening config.
     * Resolved lazily inside the closure: `about` is the only command that calls it, and
     * building the asset manifest walks `public/` and every view root.
     */
    protected function bootAbout(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Pwax', fn (): array => [
            'Version' => InstalledVersions::isInstalled('mxent/pwax')
                ? (InstalledVersions::getPrettyVersion('mxent/pwax') ?? 'dev')
                : 'dev',
            'Service Worker' => $this->config()->get('pwax.service_worker.enabled')
                ? '<fg=green;options=bold>ENABLED</>'
                : '<fg=yellow;options=bold>DISABLED</>',
            'Assets' => $this->app->make(Shell::class)->assetSource(),
            'Vue Build' => $this->app->make(RenderFunctionStore::class)->active()
                ? 'runtime (precompiled)'
                : 'full',
            'Component Cache' => $this->config()->get('pwax.cache.components', true)
                ? (string) ($this->config()->get('pwax.cache.store') ?: 'default')
                : '<fg=yellow;options=bold>OFF</>',
            'Minification' => match ($this->config()->get('pwax.minify.enabled')) {
                true => '<fg=green;options=bold>ON</>',
                false => '<fg=yellow;options=bold>OFF</>',
                default => 'production only',
            },
            'SSR' => $this->config()->get('pwax.ssr.enabled')
                ? '<fg=green;options=bold>ENABLED</>'
                : '<fg=yellow;options=bold>DISABLED</>',
        ]);
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        return $config;
    }

    /**
     * Register the SSR prerenderer.
     *
     * Bound as a singleton because it holds a per-request memo and a once-per-process
     * failure flag. The cache store follows `pwax.ssr.cache.store`, falling back to the
     * application default when null — the same convention the component cache uses.
     */
    protected function registerPrerenderer(): void
    {
        $this->app->singleton(Prerenderer::class, function ($app): Prerenderer {
            /** @var Config $config */
            $config = $app->make(Config::class);

            $store = (string) $config->get('pwax.ssr.cache.store', '') ?: null;

            return new Prerenderer(
                $config,
                $this->cacheStore($app, $store),
            );
        });
    }

    protected function registerCompiler(): void
    {
        $this->app->singleton(BlockExtractor::class);
        $this->app->singleton(StyleScoper::class);
        $this->app->singleton(TemplateStamper::class);

        $this->app->singleton(Minifier::class, function ($app): Minifier {
            /** @var Config $config */
            $config = $app->make(Config::class);

            $enabled = $config->get('pwax.minify.enabled');

            // Default to production only: while developing, a readable stack trace is
            // worth far more than the bytes minification would save.
            $enabled ??= $app->environment('production');

            if (! $enabled) {
                return new NullMinifier;
            }

            $minifier = new MatthiasMullieMinifier;

            $cache = $this->cacheStore($app, (string) $config->get('pwax.minify.store', '') ?: null);

            if ($cache === null) {
                return $minifier;
            }

            $ttl = $config->get('pwax.minify.ttl');

            return new CachedMinifier($minifier, $cache, $ttl === null ? null : (int) $ttl);
        });

        $this->app->singleton(ComponentCompiler::class, function ($app): ComponentCompiler {
            /** @var Config $config */
            $config = $app->make(Config::class);

            $cache = $config->get('pwax.cache.components', true)
                ? $this->cacheStore($app, (string) $config->get('pwax.cache.store', '') ?: null)
                : null;

            $ttl = $config->get('pwax.cache.ttl');

            return new ComponentCompiler(
                $app->make(ViewFactory::class),
                $app->make(BlockExtractor::class),
                $app->make(StyleScoper::class),
                $app->make(TemplateStamper::class),
                $app->make(Minifier::class),
                $cache,
                (bool) $config->get('pwax.components.scoped_styles', true),
                $ttl === null ? null : max(1, (int) $ttl),
                $app->bound(Dispatcher::class) ? $app->make(Dispatcher::class) : null,
            );
        });
    }

    protected function registerPwax(): void
    {
        $this->app->singleton(ComponentId::class, function ($app): ComponentId {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new ComponentId(self::normaliseKey((string) $config->get('app.key', '')));
        });

        $this->app->singleton(Pwax::class, fn ($app): Pwax => new Pwax(
            $app->make(ComponentCompiler::class),
            $app->make(ComponentId::class),
            $app->make(Config::class),
            $app->make('url'),
            $app->make(RenderFunctionStore::class),
        ));

        $this->app->alias(Pwax::class, 'pwax');

        // Bound rather than shared: it reads the current request for the CSRF token, and
        // a singleton would hold the first request it ever saw for the life of the
        // process — which is every request under Octane or FrankenPHP.
        $this->app->bind(Shell::class, fn ($app): Shell => new Shell(
            $app->make(Config::class),
            $app->make(Pwax::class),
            $app->make(ViewFactory::class),
            $app->bound('request') ? $app->make('request') : null,
            $app->make(RenderFunctionStore::class),
        ));

        $this->registerPwa();
    }

    /**
     * The progressive-web-app services: component discovery and the two manifests.
     */
    protected function registerPwa(): void
    {
        $this->app->singleton(WebManifest::class, fn ($app): WebManifest => new WebManifest(
            $app->make(Config::class),
            $app,
        ));

        $this->app->singleton(RenderFunctionStore::class, fn ($app): RenderFunctionStore => new RenderFunctionStore(
            $app->make(Config::class),
            $app->make(Filesystem::class),
        ));

        $this->app->singleton(HeadMeta::class, fn ($app): HeadMeta => new HeadMeta(
            $app->make(Config::class),
            $app->make('url'),
            $app,
        ));

        // Bound rather than shared: it renders the offline view, which reads the current
        // locale, and it memoises the bundle on the file's mtime.
        $this->app->bind(ServiceWorker::class, function ($app): ServiceWorker {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new ServiceWorker(
                $config,
                $app->make(ViewFactory::class),
                $app->make(Shell::class),
                $app->make(Filesystem::class),
                $this->cacheStore($app, (string) $config->get('pwax.cache.store', '') ?: null),
            );
        });

        $this->app->singleton(ComponentRegistry::class, fn ($app): ComponentRegistry => new ComponentRegistry(
            $app->make(ViewFactory::class),
            $app->make(Config::class),
            $app->make(Filesystem::class),
        ));

        $this->app->bind(PageRegistry::class, fn ($app): PageRegistry => new PageRegistry(
            $app->make(Router::class),
            $app->make(Config::class),
            $app->make(ComponentRegistry::class),
        ));

        // Bound per resolution, not shared: it walks `public/` and remembers what it
        // found, which is right for one manifest build and wrong for a long-lived
        // process that should notice a deploy.
        $this->app->bind(PublicAssets::class, fn ($app): PublicAssets => new PublicAssets(
            $app,
            $app->make(Config::class),
            $app->make(Filesystem::class),
        ));

        $this->app->bind(AssetManifest::class, function ($app): AssetManifest {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new AssetManifest(
                $config,
                $app->make(Pwax::class),
                $app->make(Shell::class),
                $app->make(WebManifest::class),
                $app->make(ComponentRegistry::class),
                $app->make(PageRegistry::class),
                $app,
                $app->make(ViewFactory::class),
                $app->make(PublicAssets::class),
                $this->cacheStore($app, (string) $config->get('pwax.cache.store', '') ?: null),
                $app->bound(Dispatcher::class) ? $app->make(Dispatcher::class) : null,
            );
        });
    }

    /**
     * Register the component import directive.
     *
     * The directive emits PHP rather than its final output, so the URL is resolved when
     * the view runs, not when Blade compiles it. In 1.x the directive returned a literal
     * string, which froze the resolved URL into `storage/framework/views` — so changing
     * `route_prefix`, or running `view:cache` in a build step with no routes loaded,
     * produced imports that pointed nowhere.
     *
     * The name is configurable and defaults to `@pwaxImport`. It must never be `import`: Blade
     * matches a directive even with no arguments, so `@import` would also swallow the CSS
     * at-rule `@import url("…")` in every <style> block in the application.
     */
    protected function bootDirectives(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        $name = self::assertDirectiveName((string) $config->get('pwax.components.directive', 'pwaxImport'));

        Blade::directive($name, static fn (?string $expression): string => sprintf(
            '<?php echo \Mxent\Pwax\Facades\Pwax::import(%s); ?>',
            $expression === null || trim($expression) === '' ? 'null' : $expression
        ));
    }

    /**
     * Reject directive names that would capture unrelated Blade or CSS syntax.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertDirectiveName(string $name): string
    {
        if ($name === 'import') {
            throw new \InvalidArgumentException(
                'pwax.components.directive cannot be "import": Blade matches a directive even with '
                . 'no arguments, so an "import" directive also captures the CSS at-rule '
                . '@import url("…") inside every <style> block in the application and replaces it '
                . 'with JavaScript. Use the default "pwaxImport".'
            );
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'pwax.components.directive must be a valid Blade directive name, got "%s".',
                $name
            ));
        }

        return $name;
    }

    protected function bootMiddleware(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        if (! $config->get('pwax.routes.register', true)) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('pwax', HandlePwaxRequests::class);

        // Applied to the application's own routes too, so a `pwaxRender()` route in
        // web.php gets redirect translation without the developer wiring anything.
        //
        // This goes through the HTTP kernel rather than `Router::pushMiddlewareToGroup`.
        // The kernel owns the canonical group definitions and copies them onto the router
        // with `syncMiddlewareToRouter`, which *replaces* each group wholesale — so a push
        // made directly on the router is discarded the moment the kernel is resolved.
        $groups = array_values(array_filter(
            (array) $config->get('pwax.middleware', ['web']),
            'is_string'
        ));

        if ($groups === [] || ! $this->app->bound(HttpKernelContract::class)) {
            return;
        }

        $this->app->booted(function () use ($groups): void {
            $kernel = $this->app->make(HttpKernelContract::class);

            if (! $kernel instanceof HttpKernel) {
                return;
            }

            foreach ($groups as $group) {
                $kernel->appendMiddlewareToGroup($group, HandlePwaxRequests::class);
            }
        });
    }

    protected function bootRoutes(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        if ($config->get('pwax.routes.register', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }
    }

    protected function bootPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/pwax.php' => config_path('pwax.php'),
        ], 'pwax-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/pwax'),
        ], 'pwax-views');

        // The offline document, not the worker. The worker is a built bundle now, and the
        // supported way to add to it is `service_worker.extend` — publishing 1,600 lines to
        // add ten is how a fork stops receiving fixes.
        $this->publishes([
            __DIR__ . '/../resources/views/js/offline.blade.php' => resource_path(
                'views/vendor/pwax/js/offline.blade.php'
            ),
        ], 'pwax-service-worker');

        // An annotated push-endpoint controller, the shape `pwax:push-endpoint` emits
        // without the comments. Reading it next to the README example is the difference
        // between "I think I understand" and "I have something concrete to read."
        $this->publishes([
            __DIR__ . '/../resources/views/push/example.blade.php' => resource_path(
                'views/vendor/pwax/push/example.blade.php'
            ),
        ], 'pwax-push');

        // Vue, Vue Router and Pinia, so the app works offline and leaks nothing to a CDN.
        $this->publishes([
            __DIR__ . '/../resources/vendor' => public_path('vendor/pwax'),
        ], 'pwax-assets');

        // The Pwax skill — the file an AI assistant reads before it touches a Pwax-using
        // project. `pwax:install --ai` (or `vendor:publish --tag=pwax-ai`) drops it into
        // `.ai/skills/pwax/` so a developer can point their assistant at it without
        // remembering the path.
        $this->publishes([
            __DIR__ . '/../resources/ai/pwax-skill.md' => base_path('.ai/skills/pwax/SKILL.md'),
        ], 'pwax-ai');
    }

    /**
     * Resolve a cache repository, tolerating applications with no cache configured.
     */
    private function cacheStore(mixed $app, ?string $store): ?CacheRepository
    {
        if (! $app->bound(CacheFactory::class)) {
            return null;
        }

        try {
            return $app->make(CacheFactory::class)->store($store);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Turn Laravel's `base64:` application key into raw bytes.
     */
    private static function normaliseKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? $key : $decoded;
        }

        return $key;
    }
}
