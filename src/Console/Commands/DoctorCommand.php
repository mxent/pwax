<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mxent\Pwax\Pwa\AssetManifest;
use Mxent\Pwax\Pwa\ComponentRegistry;
use Mxent\Pwax\Pwa\Ssr\Prerenderer;
use Mxent\Pwax\Pwa\Strategy;
use Mxent\Pwax\Pwa\WebManifest;
use Mxent\Pwax\Pwax;
use Mxent\Pwax\Support\RenderFunctionStore;
use Mxent\Pwax\Support\Shell;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Checks the things that are easy to get wrong and hard to notice: a missing
 * application key, an uninstallable manifest, a CDN-only asset strategy in a package
 * whose whole purpose is working offline, a directive name that corrupts CSS.
 */
class DoctorCommand extends Command
{
    protected $signature = 'pwax:doctor';

    protected $description = 'Check the Pwax installation for common misconfigurations';

    private int $problems = 0;

    private int $warnings = 0;

    public function __construct(
        private readonly WebManifest $manifest,
        private readonly AssetManifest $assets,
        private readonly ComponentRegistry $registry,
        private readonly RenderFunctionStore $store,
    ) {
        parent::__construct();
    }

    public function handle(Config $config, Pwax $pwax): int
    {
        $this->components->info('Checking your Pwax installation');

        $this->checkAppKey($config);
        $this->checkRemovedConfig($config);
        $this->checkDirective($config);
        $this->checkAssets($config);
        $this->checkCrossOriginPolicy($config);
        $this->checkRuntimeBundle();
        $this->checkPrecompiledTemplates($pwax);
        $this->checkManifest($config);
        $this->checkServiceWorker($config);
        $this->checkPrecache($config);
        $this->checkRouting($config);
        $this->checkExtend($config);
        $this->checkPush($config);
        $this->checkCacheStore($config);
        $this->checkServiceWorkerPath($config);
        $this->checkScope($config);
        $this->checkManifestId($config);
        $this->checkDisplayMode($config);
        $this->checkWorkerSourceMap($config);
        $this->checkPushSubscriptionsTable($config);
        $this->checkSsr($config);
        $this->checkHead($config);

        $this->newLine();

        if ($this->problems > 0) {
            $this->components->error(sprintf(
                '%d problem(s) and %d warning(s) found.',
                $this->problems,
                $this->warnings
            ));

            return self::FAILURE;
        }

        $this->components->info($this->warnings > 0
            ? sprintf('No problems, %d warning(s).', $this->warnings)
            : 'Everything looks good.');

        return self::SUCCESS;
    }

    /**
     * Config keys a major version removed, and what replaced them.
     *
     * A published `config/pwax.php` is the application's file, so an upgrade cannot
     * rewrite it. Left unattended these keys do nothing at all — the most confusing
     * possible outcome, since the file still reads as though the feature is configured.
     * Naming the replacement here is what turns a clean break into a guided one.
     */
    private function checkRemovedConfig(Config $config): void
    {
        $replacements = [
            'pwax.service_worker.precache' => 'service_worker.pages.urls, though most routes are now discovered automatically',
            'pwax.service_worker.files' => 'service_worker.asset_groups, which takes globs',
            'pwax.helpers.global' => 'nothing — vue() and router() were removed in 3.0',
            'pwax.service_worker.strategy' => 'service_worker.runtime_strategy, which now defaults to network-only',
            'pwax.service_worker.identity_cache_limit' => 'nothing — caches are shared across visitors, so there is no per-person set left to bound',
        ];

        foreach ($replacements as $key => $replacement) {
            if ($config->get($key) === null) {
                continue;
            }

            $this->warn_(sprintf('%s no longer does anything. Use %s.', $key, $replacement));
        }

        if ($config->get('pwax.components.directive') === 'pwax') {
            $this->warn_(
                'pwax.components.directive is set to "pwax". @pwaxImport is the canonical '
                . 'name and is always registered; keeping this only means both work.'
            );
        }

        $this->checkDataGroupShape($config);
        $this->checkStrategyNames($config);
    }

    /**
     * Strategy names the package does not recognise.
     *
     * `freshness`, `performance` and `app-shell` were accepted as aliases through 4.x and
     * were removed in 5.0; so was `assets.strategy`, the key that chose a hostname in a
     * config where every other "strategy" chooses a caching behaviour. An unrecognised
     * value now falls back to the default at the point of use, silently — which is right
     * for serving a page and useless for finding out why the page is served that way.
     *
     * A problem rather than a warning: a config key that is not doing what it says is not
     * a matter of taste, and the whole reason to remove an alias is to stop it being
     * ambiguous.
     */
    private function checkStrategyNames(Config $config): void
    {
        $keys = [
            'pwax.service_worker.runtime_strategy',
            'pwax.service_worker.navigation_strategy',
            'pwax.service_worker.pages.strategy',
        ];

        $names = 'network-only, network-first, cache-first or stale-while-revalidate';

        // A value that is not a string is still wrong, and still has to be printable. Cast
        // blindly and a `'strategy' => []` typo makes the doctor emit a PHP warning in the
        // middle of the message explaining the mistake.
        $shown = static fn (mixed $value): string => is_string($value) ? $value : get_debug_type($value);

        foreach ((array) $config->get('pwax.service_worker.data_groups', []) as $index => $group) {
            if (is_array($group) && Strategy::isUnknown($group['strategy'] ?? null)) {
                $this->fail_(sprintf(
                    'Data group "%s" has an unknown strategy "%s". It is being ignored and the '
                        . 'group falls back to the default. Use one of: %s.',
                    is_string($group['name'] ?? null) ? $group['name'] : (string) $index,
                    $shown($group['strategy']),
                    $names,
                ));
            }
        }

        foreach ($keys as $key) {
            $value = $config->get($key);

            if (Strategy::isUnknown($value)) {
                $this->fail_(sprintf(
                    '%s has an unknown strategy "%s". It is being ignored and the default applies '
                        . 'instead. Use one of: %s.',
                    $key,
                    $shown($value),
                    $names,
                ));
            }
        }

        if ($config->get('pwax.assets.strategy') !== null) {
            $this->fail_(
                'pwax.assets.strategy was removed in 5.0 and is no longer read — rename it to '
                . 'pwax.assets.source. It chooses where the framework is served from, not how '
                . 'anything is cached, and until it is renamed the framework is being served '
                . 'from wherever pwax.assets.source says, which defaults to local.'
            );
        }
    }

    /**
     * Data groups written the 3.x way.
     *
     * The nested `cache_config` is now flat, and `max_size` is `max_entries` — the same
     * quantity the pages block and the runtime cache already spelled that way. A group
     * left in the old shape is not an error the worker can see: it reads defaults and
     * caches happily, with none of the bounds its author wrote.
     */
    private function checkDataGroupShape(Config $config): void
    {
        foreach ((array) $config->get('pwax.service_worker.data_groups', []) as $group) {
            if (! is_array($group)) {
                continue;
            }

            $name = is_string($group['name'] ?? null) ? $group['name'] : 'a data group';

            if (isset($group['cache_config'])) {
                $this->warn_(sprintf(
                    'Data group "%s" still nests its settings under cache_config. Move strategy, '
                    . 'max_age and timeout onto the group itself, or it runs on defaults.',
                    $name
                ));
            }

            if (isset($group['max_size'])) {
                $this->warn_(sprintf('Data group "%s" uses max_size. It is now max_entries.', $name));
            }
        }
    }

    private function checkAppKey(Config $config): void
    {
        $this->assert(
            (string) $config->get('app.key', '') !== '',
            'APP_KEY is set',
            'APP_KEY is empty. Component identifiers cannot be signed. Run `php artisan key:generate`.'
        );
    }

    /**
     * Report which directive imports a component.
     *
     * Only reports it. This used to assert that the name was not `import` — the 1.x
     * default, which also matched the CSS at-rule inside `<style>` blocks — but
     * `PwaxServiceProvider::assertDirectiveName()` throws at boot on that name, so an
     * application configured that way cannot start, let alone reach this command. A check
     * that can never fail is a line of output pretending to be a result.
     */
    private function checkDirective(Config $config): void
    {
        $this->ok(sprintf(
            'Import directive is @%s',
            (string) $config->get('pwax.components.directive', 'pwaxImport')
        ));
    }

    private function checkAssets(Config $config): void
    {
        $strategy = $this->laravel->make(Shell::class)->assetSource();

        if ($strategy === 'cdn') {
            $this->warn_(
                'Assets load from a CDN. The app cannot start offline, and every visitor\'s IP '
                . 'is disclosed to the CDN. Set pwax.assets.source to "local" and run '
                . '`php artisan vendor:publish --tag=pwax-assets`.'
            );

            if ($config->get('pwax.assets.cdn.integrity', []) === []) {
                $this->warn_('CDN assets have no subresource integrity hashes configured.');
            }

            return;
        }

        $directory = public_path(trim((string) $config->get('pwax.assets.local_path', '/vendor/pwax'), '/'));

        $builds = ['vue.global.prod.js'];

        // The runtime-only build is served whenever precompiling is on and has produced
        // something, so an application in that state needs both files present: the small
        // one for the ordinary path, and the full one for the fallback when the store is
        // empty. Publishing ships the directory, so a missing file means a partial copy.
        if ($this->store->wanted()) {
            $builds[] = 'vue.runtime.global.prod.js';
        }

        foreach ($builds as $build) {
            $this->assert(
                is_file($directory . '/' . $build),
                sprintf('%s is published locally', $build),
                sprintf('%s/%s is missing. Run `php artisan vendor:publish --tag=pwax-assets`.', $directory, $build)
            );
        }
    }

    /**
     * Cross-origin isolation is enabled by default on the HTML shell. Every cross-origin
     * asset the application loads therefore needs a `crossorigin` attribute, or the
     * browser refuses to load it.
     */
    private function checkCrossOriginPolicy(Config $config): void
    {
        $coep = $config->get('pwax.security.cross_origin_embedder_policy');

        if ($coep === null || $coep === '') {
            return;
        }

        foreach ((array) $config->get('pwax.scripts', []) as $script) {
            $src = is_array($script) ? ($script['src'] ?? '') : (string) $script;

            if ($src === '' || ! $this->isCrossOriginUrl($src)) {
                continue;
            }

            $crossorigin = is_array($script) ? ($script['crossorigin'] ?? null) : null;

            if ($crossorigin === null) {
                $this->warn_(
                    sprintf(
                        'Cross-origin script "%s" is loaded without a `crossorigin` attribute, '
                        . 'so the browser will refuse to load it under '
                        . '`Cross-Origin-Embedder-Policy: %s`. Add `crossorigin` to its config entry.',
                        $src,
                        $coep
                    )
                );
            }
        }

        foreach ((array) $config->get('pwax.styles', []) as $style) {
            $href = is_array($style) ? ($style['href'] ?? '') : (string) $style;

            if ($href === '' || ! $this->isCrossOriginUrl($href)) {
                continue;
            }

            $crossorigin = is_array($style) ? ($style['crossorigin'] ?? null) : null;

            if ($crossorigin === null) {
                $this->warn_(
                    sprintf(
                        'Cross-origin stylesheet "%s" is loaded without a `crossorigin` attribute, '
                        . 'so the browser will refuse to load it under '
                        . '`Cross-Origin-Embedder-Policy: %s`. Add `crossorigin` to its config entry.',
                        $href,
                        $coep
                    )
                );
            }
        }
    }

    private function isCrossOriginUrl(string $url): bool
    {
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }

        $appUrl = (string) config('app.url');

        if ($appUrl === '') {
            return true;
        }

        $host = parse_url($appUrl, PHP_URL_HOST) ?: '';
        $urlHost = parse_url($url, PHP_URL_HOST) ?: '';

        return $host === '' || $urlHost === '' || strcasecmp($host, $urlHost) !== 0;
    }

    private function checkRuntimeBundle(): void
    {
        $this->assert(
            is_file(dirname(__DIR__, 3) . '/dist/pwax.js'),
            'Client runtime bundle is present',
            'dist/pwax.js is missing from the package. Reinstall with `composer reinstall mxent/pwax`.'
        );
    }

    /**
     * The one configuration in this package that can produce a blank page.
     *
     * `assets.vue_build => 'runtime'` serves a Vue with no compiler in it. Every template
     * therefore has to have been precompiled, and `pwax:compile` has to be re-run whenever
     * a component changes — a deploy step that is easy to add once and easy to forget
     * thereafter. Three states are worth separating:
     *
     *   - Asked for, nothing compiled. `Shell` falls back to the full build, so the site
     *     works and is merely slower than intended. Reported as a problem all the same:
     *     a silent fallback is a regression nobody can find.
     *   - Asked for, compiled, but a component has changed since. This is the one that
     *     breaks: the store is non-empty so the runtime-only build is served, and the
     *     changed component has no render function under its new key.
     *   - Compiled but not asked for. Harmless, just unused, so it is only a warning.
     */
    private function checkPrecompiledTemplates(Pwax $pwax): void
    {
        $store = $this->store;

        if (! $store->wanted()) {
            if ($store->count() > 0) {
                $this->warn_(
                    'Render functions are compiled but unused. Set pwax.assets.vue_build to '
                    . '"runtime" to serve the smaller Vue build, or run `php artisan pwax:compile '
                    . '--clear`.'
                );
            }

            return;
        }

        if ($store->count() === 0) {
            $this->fail_(
                'pwax.assets.vue_build is "runtime" but no render functions are compiled, so the '
                . 'full Vue build is being served instead. Run `php artisan pwax:compile`.'
            );

            return;
        }

        $stale = [];

        foreach ($this->registry->precachable() as $component) {
            $view = $component['view'];

            try {
                $template = $pwax->compile($view)->template;
            } catch (Throwable) {
                // A view that will not render without controller data cannot be
                // precompiled, and `pwax:compile` already reported it as such.
                continue;
            }

            if (trim($template) !== '' && $store->get($template) === null) {
                $stale[] = $view;
            }
        }

        if ($stale === []) {
            $this->ok(sprintf('Render functions cover all %d component(s)', $store->count()));

            return;
        }

        $this->fail_(sprintf(
            '%d component(s) have changed since `php artisan pwax:compile` last ran (%s). '
            . 'Under the runtime-only Vue build they will render nothing. Re-run pwax:compile.',
            count($stale),
            implode(', ', array_slice($stale, 0, 5)) . (count($stale) > 5 ? ', …' : '')
        ));
    }

    private function checkManifest(Config $config): void
    {
        /** @var array<string, mixed> $manifest */
        $manifest = $config->get('pwax.manifest', []);

        foreach (['name', 'start_url', 'display'] as $field) {
            $this->assert(
                ! empty($manifest[$field]),
                sprintf('Manifest has %s', $field),
                sprintf('pwax.manifest.%s is empty; the app will not be installable.', $field)
            );
        }

        $sizes = $this->manifest->declaredSizes();

        // Chromium requires both to offer an install prompt.
        foreach ([192, 512] as $required) {
            $this->assert(
                in_array($required, $sizes, true),
                sprintf('Manifest has a %dx%d icon', $required, $required),
                sprintf(
                    'pwax.manifest.icons has no %dx%d entry; browsers will not offer to install the app.',
                    $required,
                    $required
                )
            );
        }

        if (! $this->manifest->hasMaskableIcon()) {
            $this->warn_(
                'No icon declares purpose "maskable". Android will draw the icon inside a white '
                . 'rounded square instead of filling the launcher shape.'
            );
        }

        if (empty($manifest['id'])) {
            $this->warn_(
                'pwax.manifest.id is not set, so the installed app is identified by start_url. '
                . 'Changing start_url later would orphan every existing install; set id now and '
                . 'never change it.'
            );
        }

        $scope = (string) ($manifest['scope'] ?? '/');
        $start = (string) ($manifest['start_url'] ?? '/');

        $this->assert(
            str_starts_with($this->pathOf($start), $this->pathOf($scope)),
            'Manifest start_url is inside scope',
            sprintf(
                'pwax.manifest.start_url (%s) is outside pwax.manifest.scope (%s). The manifest '
                . 'is invalid and the app will not install.',
                $start,
                $scope
            )
        );

        if (($manifest['screenshots'] ?? []) === []) {
            $this->warn_(
                'pwax.manifest.screenshots is empty. Chromium falls back to a minimal install '
                . 'prompt without at least one wide and one narrow screenshot.'
            );
        }

        $this->checkManifestTargets($manifest, $scope);
    }

    /**
     * The manifest members that name a URL the operating system will send the app to.
     *
     * `share_target`, `file_handlers`, `protocol_handlers` and `shortcuts` are declarations
     * that something outside the browser may now open this application at a particular
     * address. They pass straight through to the manifest, so declaring one is a promise
     * the package cannot keep on the application's behalf — and the failure lands on a user
     * who shared a photo to an installed app and got a 404, hours after the deploy, with
     * nothing in any log tying it to a config change.
     *
     * Every declared target is resolved against the real route table here, with the method
     * the browser will actually use. A target outside `scope` is checked too: the browser
     * silently ignores those, so the member appears to work and simply never fires.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function checkManifestTargets(array $manifest, string $scope): void
    {
        $targets = [];

        /** @var array<string, mixed>|null $share */
        $share = is_array($manifest['share_target'] ?? null) ? $manifest['share_target'] : null;

        if ($share !== null) {
            $this->checkShareTarget($share);

            $method = strtoupper((string) ($share['method'] ?? 'GET'));

            $targets['share_target.action'] = [
                (string) ($share['action'] ?? ''),
                in_array($method, ['GET', 'POST'], true) ? $method : 'GET',
            ];
        }

        foreach ((array) ($manifest['file_handlers'] ?? []) as $index => $handler) {
            if (! is_array($handler)) {
                continue;
            }

            if (! is_array($handler['accept'] ?? null) || $handler['accept'] === []) {
                $this->fail_(sprintf(
                    'pwax.manifest.file_handlers.%s has no "accept" map, so the browser will '
                    . 'register it for nothing. Give it {"text/csv": [".csv"]}.',
                    (string) $index
                ));
            }

            $targets[sprintf('file_handlers.%s.action', (string) $index)]
                = [(string) ($handler['action'] ?? ''), 'GET'];
        }

        foreach ((array) ($manifest['protocol_handlers'] ?? []) as $index => $handler) {
            if (! is_array($handler)) {
                continue;
            }

            $protocol = (string) ($handler['protocol'] ?? '');
            $url = (string) ($handler['url'] ?? '');

            // Custom schemes must be `web+…`; the rest of the allowance is a fixed list in
            // the specification. A scheme outside both is dropped without a word.
            if ($protocol !== '' && ! str_starts_with($protocol, 'web+')
                && ! in_array($protocol, self::SAFELISTED_SCHEMES, true)) {
                $this->fail_(sprintf(
                    'pwax.manifest.protocol_handlers.%s registers "%s". Custom schemes must begin '
                    . 'with "web+"; browsers ignore the handler otherwise.',
                    (string) $index,
                    $protocol
                ));
            }

            if ($url !== '' && ! str_contains($url, '%s')) {
                $this->fail_(sprintf(
                    'pwax.manifest.protocol_handlers.%s has no %%s in its url, so the handler has '
                    . 'nowhere to put the link that was followed.',
                    (string) $index
                ));
            }

            // `%s` is where the browser substitutes the whole percent-encoded link. Any
            // value stands in for the route test; what is being checked is the path.
            $targets[sprintf('protocol_handlers.%s.url', (string) $index)]
                = [str_replace('%s', 'x', $url), 'GET'];
        }

        foreach ((array) ($manifest['shortcuts'] ?? []) as $index => $shortcut) {
            if (is_array($shortcut)) {
                $targets[sprintf('shortcuts.%s.url', (string) $index)]
                    = [(string) ($shortcut['url'] ?? ''), 'GET'];
            }
        }

        if ($targets === []) {
            return;
        }

        $broken = 0;

        foreach ($targets as $key => [$url, $method]) {
            $broken += $this->checkTarget($key, $url, $method, $scope) ? 0 : 1;
        }

        if ($broken === 0) {
            $this->ok(sprintf('All %d manifest target(s) resolve', count($targets)));
        }
    }

    /**
     * Schemes a protocol handler may claim without the `web+` prefix.
     *
     * @var list<string>
     */
    private const SAFELISTED_SCHEMES = [
        'bitcoin', 'cabal', 'dat', 'did', 'doi', 'dweb', 'ethereum', 'ftp', 'ftps', 'geo',
        'gopher', 'hyper', 'im', 'ipfs', 'ipns', 'irc', 'ircs', 'magnet', 'mailto', 'matrix',
        'mms', 'news', 'nntp', 'openpgp4fpr', 'sftp', 'sip', 'sms', 'smsto', 'ssb', 'ssh',
        'tel', 'urn', 'webcal', 'wtai', 'xmpp',
    ];

    /**
     * One declared target: inside scope, and answered by a route.
     */
    private function checkTarget(string $key, string $url, string $method, string $scope): bool
    {
        if (trim($url) === '') {
            $this->fail_(sprintf('pwax.manifest.%s is empty.', $key));

            return false;
        }

        $path = $this->pathOf($url);

        if (! str_starts_with($path, $this->pathOf($scope))) {
            $this->fail_(sprintf(
                'pwax.manifest.%s (%s) is outside pwax.manifest.scope (%s), so the browser '
                . 'will ignore it.',
                $key,
                $url,
                $scope
            ));

            return false;
        }

        try {
            $this->laravel->make('router')->getRoutes()->match(Request::create($path, $method));

            return true;
        } catch (MethodNotAllowedHttpException) {
            $this->fail_(sprintf(
                'pwax.manifest.%s (%s) has a route, but it does not accept %s — which is the '
                . 'method the browser will use.',
                $key,
                $url,
                $method
            ));
        } catch (NotFoundHttpException) {
            $this->fail_(sprintf(
                'pwax.manifest.%s (%s) matches no route. The manifest tells the operating '
                . 'system to send the app there; nothing answers.',
                $key,
                $url
            ));
        } catch (Throwable $e) {
            // A route that needs a bound model, a domain constraint the CLI cannot satisfy.
            // Not knowing is not the same as knowing it is broken.
            $this->warn_(sprintf(
                'pwax.manifest.%s (%s) could not be resolved here: %s',
                $key,
                $url,
                $e->getMessage()
            ));

            return true;
        }

        return false;
    }

    /**
     * The share target's own shape, which the route table cannot speak to.
     *
     * @param  array<string, mixed>  $share
     */
    private function checkShareTarget(array $share): void
    {
        $method = strtoupper((string) ($share['method'] ?? 'GET'));

        if (! in_array($method, ['GET', 'POST'], true)) {
            $this->fail_(sprintf(
                'pwax.manifest.share_target.method is "%s". Only GET and POST are allowed.',
                $method
            ));
        }

        /** @var array<string, mixed> $params */
        $params = is_array($share['params'] ?? null) ? $share['params'] : [];

        $hasFiles = ($params['files'] ?? []) !== [];

        if ($hasFiles && $method !== 'POST') {
            $this->fail_(
                'pwax.manifest.share_target accepts files but is not a POST. A shared file '
                . 'cannot be delivered in a query string.'
            );
        }

        $enctype = strtolower((string) ($share['enctype'] ?? ''));

        if ($method === 'POST' && $hasFiles && $enctype !== 'multipart/form-data') {
            $this->fail_(
                'pwax.manifest.share_target accepts files, so its enctype must be '
                . '"multipart/form-data".'
            );
        }

        if ($method === 'POST') {
            $this->warn_(
                'pwax.manifest.share_target posts to your application from outside it, so its '
                . 'route needs CSRF exemption and its own validation. The service worker leaves '
                . 'non-GET requests to the network, so a share received offline fails rather '
                . 'than being queued — deliberately: replaying an arbitrary POST is not '
                . 'something a package can decide for you.'
            );
        }
    }

    private function checkServiceWorker(Config $config): void
    {
        if (! $config->get('pwax.service_worker.enabled', false)) {
            $this->warn_('The service worker is disabled, so the app will not work offline.');

            return;
        }

        $path = (string) $config->get('pwax.service_worker.path', '/sw.js');

        $this->assert(
            substr_count(trim($path, '/'), '/') === 0,
            'Service worker is served from the root',
            sprintf(
                'The service worker is served from %s. A worker can only control paths at or below '
                . 'its own URL, so serve it from the root to cover the whole site.',
                $path
            )
        );

        $this->assert(
            (bool) $config->get('pwax.service_worker.shell.enabled', true),
            'Offline app shell is enabled',
            'pwax.service_worker.shell.enabled is false, so a navigation made offline has nothing '
            . 'to fall back to and the browser shows its own error page.'
        );

        $this->assert(
            (bool) $config->get('pwax.service_worker.assets', true),
            'Framework and runtime are precached',
            'pwax.service_worker.assets is false, so Vue and the client runtime are only cached '
            . 'after they have been fetched online. A first visit followed by going offline will '
            . 'show nothing at all.'
        );

        if ($config->get('pwax.service_worker.components', 'all') === false) {
            $this->warn_(
                'pwax.service_worker.components is false, so neither components nor pages are '
                . 'precached — that setting scopes both. Nothing is available offline until it '
                . 'has been loaded online at least once.'
            );
        }
    }

    /**
     * Report how much of the application is actually reachable offline.
     */
    private function checkPrecache(Config $config): void
    {
        if (! $config->get('pwax.service_worker.enabled', false)) {
            return;
        }

        try {
            $manifest = $this->assets->build();
        } catch (Throwable $e) {
            $this->problems++;
            $this->components->twoColumnDetail(
                'The asset manifest could not be built: ' . $e->getMessage(),
                '<fg=red>FAIL</>'
            );

            return;
        }

        /** @var list<array{name: string, urls: list<string>}> $groups */
        $groups = $manifest['assetGroups'] ?? [];

        $counts = [];
        $total = 0;

        foreach ($groups as $group) {
            $counts[] = sprintf('%d %s', count($group['urls']), $group['name']);
            $total += count($group['urls']);
        }

        $this->assert(
            $total > 0,
            sprintf('Asset manifest lists %s', $counts === [] ? 'nothing' : implode(', ', $counts)),
            'The asset manifest is empty, so the service worker has nothing to install.'
        );

        // Truncated globs are reported rather than fatal, which is right and easy to
        // miss. Surface them where someone is already looking for problems.
        /** @var list<string> $warnings */
        $warnings = $manifest['warnings'] ?? [];

        foreach ($warnings as $warning) {
            $this->warn_($warning);
        }

        // The symptom that sends people looking: everything caches, `pwax-pages` is empty,
        // and offline shows a connection error. Usually it means no route was discoverable
        // and none was listed, so nothing was ever precached as a page.
        $pages = 0;

        foreach ($groups as $group) {
            if (($group['kind'] ?? null) === 'page') {
                $pages = count($group['urls']);
            }
        }

        if ($pages === 0) {
            $this->warn_(
                'No pages will be precached, so a route works offline only after it has '
                . 'been visited. Discovery reads each GET route for a literal view name '
                . 'given to pwaxRender(); a computed name or a parameterised route cannot '
                . 'be read and belongs in service_worker.pages.urls.'
            );
        }

        // The page caches are bounded and evicted oldest-first, and an install fills them
        // before any browsing does. Precache more pages than the bound and the install's
        // own work is the first thing thrown away — silently, on the visitor's first few
        // navigations, leaving exactly the routes they never opened missing offline.
        $maxEntries = (int) $config->get('pwax.service_worker.pages.max_entries', 60);

        if ($pages > 0 && $maxEntries > 0 && $pages > $maxEntries) {
            $this->warn_(sprintf(
                '%d pages are precached but service_worker.pages.max_entries is %d, so '
                . 'browsing evicts what the install stored. Raise it to at least %d.',
                $pages,
                $maxEntries,
                $pages
            ));
        }

        if ((bool) $config->get('pwax.service_worker.pages.runtime', true)) {
            $this->warn_(
                'Pages are cached as they are visited (service_worker.pages.runtime). Caches '
                . 'are shared across visitors — anyone with the device gets the same offline '
                . 'pages the last user had. Use ->offline(false) on routes whose content must '
                . 'not be stored at all.'
            );
        }

        $components = count($this->registry->all());
        $selected = count($this->registry->precachable());

        if ($components > 0 && $selected < $components) {
            $this->warn_(sprintf(
                '%d of %d components are excluded from precaching. Run `php artisan pwax:precache` '
                . 'to see which.',
                $components - $selected,
                $components
            ));
        }

        /** @var list<string> $crossOrigin */
        $crossOrigin = $manifest['crossOrigin'] ?? [];

        if ($crossOrigin !== []) {
            $this->warn_(sprintf(
                '%d asset(s) are cross-origin. They are precached on a best-effort basis and an '
                . 'install will not fail if the CDN is unreachable — but the app may not start '
                . 'offline until it is.',
                count($crossOrigin)
            ));
        }
    }

    /**
     * The path component of a manifest URL, which may be absolute or relative.
     */
    private function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function checkRouting(Config $config): void
    {
        $middleware = (array) $config->get('pwax.middleware', []);

        $this->assert(
            $middleware !== [],
            'Component routes have middleware',
            'pwax.middleware is empty, so components render with no session and auth() is always '
            . 'a guest. Set it to ["web"].'
        );

        if ($config->get('pwax.hash_route', false)) {
            $this->warn_(
                'Hash routing is on. URLs will contain "#/", which search engines will not index. '
                . 'Turn it off unless your host cannot rewrite unknown paths to index.php.'
            );
        }
    }

    /**
     * Every `service_worker.extend` entry resolves to something the worker can read.
     *
     * An entry that misses is not fatal — `ServiceWorker::build()` writes a comment in
     * its place rather than bringing the whole offline application down — so the only
     * way a typo surfaces is the developer spotting the comment in a checked-in
     * `dist/pwax.js`. That, and the runtime calls a handler that never got registered.
     */
    private function checkExtend(Config $config): void
    {
        $entries = (array) $config->get('pwax.service_worker.extend', []);

        if ($entries === []) {
            return;
        }

        $views = $this->laravel->make('view');

        $broken = 0;

        foreach ($entries as $index => $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }

            // Views are resolved by the View factory; raw files are read from disk.
            if ($views->exists($entry)) {
                continue;
            }

            if (is_file($entry) && is_readable($entry)) {
                continue;
            }

            $this->fail_(sprintf(
                'service_worker.extend entry "%s" resolves to neither a view nor a readable file. '
                . 'The worker will skip it.',
                $entry
            ));
            $broken++;
        }

        if ($broken === 0) {
            $this->ok(sprintf('All %d service_worker.extend entry(ies) resolve', count($entries)));
        }
    }

    /**
     * VAPID keys are well-formed, and `push.endpoint` is reachable when set.
     *
     * The Push API rejects a subscription whose public key will not decode to a valid
     * uncompressed point, and rejects a server whose private key will not match it. A
     * typo in either is a 401 from the push service that is hard to tell from "the
     * service is having a bad day". Both keys are validated here at the same shape
     * `pwax:vapid` emits.
     */
    private function checkPush(Config $config): void
    {
        $publicKey = (string) $config->get('pwax.push.public_key', '');
        $privateKey = (string) $config->get('pwax.push.private_key', '');
        $endpoint = (string) $config->get('pwax.push.endpoint', '');

        if ($publicKey === '' && $privateKey === '' && $endpoint === '') {
            return;
        }

        if ($publicKey !== '') {
            $bytes = self::base64urlDecode($publicKey);

            $this->assert(
                is_string($bytes) && strlen($bytes) === 65 && $bytes[0] === "\x04",
                'Push public key is a valid uncompressed P-256 point',
                'pwax.push.public_key must be base64url-encoded and decode to 65 bytes (an '
                . 'uncompressed P-256 point, starting with 0x04). Run `php artisan pwax:vapid` '
                . 'to generate a fresh pair.'
            );
        }

        if ($privateKey !== '') {
            $bytes = self::base64urlDecode($privateKey);

            $this->assert(
                is_string($bytes) && strlen($bytes) === 32,
                'Push private key is a 32-byte scalar',
                'pwax.push.private_key must be base64url-encoded and decode to 32 bytes. Run '
                . '`php artisan pwax:vapid` to generate a fresh pair.'
            );
        }

        if ($publicKey !== '' && $endpoint === '') {
            $this->warn_(
                'pwax.push.public_key is set but pwax.push.endpoint is not. Subscriptions will '
                . 'have nowhere to deliver to — every push will fail server-side.'
            );
        }

        if ($endpoint !== '') {
            $this->probe($endpoint, 'Push subscription endpoint is reachable');
        }
    }

    /**
     * The configured cache store is alive enough to round-trip a value.
     *
     * A store that throws on every read makes every render hit the Blade compiler, the
     * CSS scoper and the inline-block extractor. The `pwax:doctor` pass is the only time
     * such a misconfiguration is reported in a place the developer is looking.
     */
    private function checkCacheStore(Config $config): void
    {
        $store = (string) $config->get('cache.default', 'array');

        try {
            $cache = $this->laravel->make('cache')->store($store);
            $key = 'pwax-doctor-probe';
            $cache->put($key, '1', 30);
            $value = $cache->get($key);
            $cache->forget($key);

            $this->assert(
                $value === '1',
                sprintf('Cache store "%s" round-trips', $store),
                sprintf('Cache store "%s" did not return the value just written.', $store)
            );
        } catch (Throwable $e) {
            $this->fail_(sprintf(
                'Cache store "%s" threw: %s. Every render will be uncached.',
                $store,
                $e->getMessage()
            ));
        }
    }

    /**
     * The service worker is served at all, and as JavaScript.
     *
     * A worker registration that points at a 404 — or at an HTML login page — is the
     * "nothing happens when I reload offline" failure mode. The request that hits
     * `/sw.js` here is the same shape the browser sends, so a missing route or a
     * `<a>` tag named `sw.js` is visible before the install attempt.
     */
    private function checkServiceWorkerPath(Config $config): void
    {
        if (! $config->get('pwax.service_worker.enabled', false)) {
            return;
        }

        $path = '/' . trim((string) $config->get('pwax.service_worker.path', '/sw.js'), '/');
        $url = rtrim((string) $config->get('app.url'), '/') . $path;

        try {
            $response = Http::timeout(5)->get($url);
        } catch (Throwable $e) {
            $this->fail_(sprintf('Service worker URL %s could not be fetched: %s', $url, $e->getMessage()));

            return;
        }

        $contentType = $response->header('Content-Type') ?? '';

        $this->assert(
            $response->successful(),
            'Service worker URL responds 2xx',
            sprintf('Service worker URL %s returned %d.', $url, $response->status())
        );

        $this->assert(
            str_contains($contentType, 'javascript'),
            'Service worker URL serves JavaScript',
            $response->status() === 200
                ? sprintf('Service worker URL %s responded with Content-Type "%s" — a browser will '
                    . 'refuse to register it as a worker.', $url, $contentType)
                : 'Worker URL did not respond 2xx, so its Content-Type is not meaningful.'
        );
    }

    /**
     * Decode a base64url string. Returns the decoded bytes, or `null` on invalid input.
     */
    private static function base64urlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Head-check a URL. Treats non-2xx as a problem, network failures as a warning —
     * a config that points at the right host but the wrong path is a clear typo;
     * a host that is down for five seconds is not something `pwax:doctor` should
     * pretend to know about.
     */
    private function probe(string $url, string $pass): void
    {
        try {
            $response = Http::timeout(5)->get($url);
        } catch (Throwable $e) {
            $this->warn_(sprintf('Could not reach %s: %s', $url, $e->getMessage()));

            return;
        }

        if ($response->status() >= 500) {
            $this->fail_(sprintf('%s returned %d — push subscriptions will fail.', $url, $response->status()));

            return;
        }

        if ($response->status() >= 400) {
            $this->warn_(sprintf('%s returned %d — the URL exists but does not accept this request.', $url, $response->status()));

            return;
        }

        $this->ok($pass);
    }

    private function assert(bool $ok, string $pass, string $fail): void
    {
        if ($ok) {
            $this->components->twoColumnDetail($pass, '<fg=green>OK</>');

            return;
        }

        $this->problems++;
        $this->components->twoColumnDetail($fail, '<fg=red>FAIL</>');
    }

    private function warn_(string $message): void
    {
        $this->warnings++;
        $this->components->twoColumnDetail($message, '<fg=yellow>WARN</>');
    }

    /**
     * Report a problem with no matching pass line, for checks whose success is reported
     * separately or is simply the absence of anything to say.
     */
    private function fail_(string $message): void
    {
        $this->problems++;
        $this->components->twoColumnDetail($message, '<fg=red>FAIL</>');
    }

    /**
     * State a fact. Unlike `assert()` there is no failing branch — this is for things
     * worth showing that cannot be wrong.
     */
    private function ok(string $message): void
    {
        $this->components->twoColumnDetail($message, '<fg=green>OK</>');
    }

    /**
     * The service worker's scope must be `/` or `/<segment>` and contain no fragment.
     *
     * A scope the worker cannot claim — a path that has its own URL resolution, a
     * fragment, a value that begins with the worker's own path — is silently ignored
     * by `navigator.serviceWorker.register()`. The browser installs the worker but
     * leaves it controlling nothing, and the symptom is "the worker is on according to
     * DevTools but a network navigation never reaches it."
     */
    private function checkScope(Config $config): void
    {
        $scope = (string) $config->get('pwax.service_worker.scope', '/');

        if (str_contains($scope, '#')) {
            $this->fail_(sprintf(
                'pwax.service_worker.scope (%s) contains a fragment. Browsers reject the '
                . 'registration and the worker will not control anything.',
                $scope
            ));

            return;
        }

        if ($scope !== '/' && ! preg_match('#^/[A-Za-z0-9_.\-]*$#', $scope)) {
            $this->warn_(sprintf(
                'pwax.service_worker.scope (%s) is not absolute or contains characters a '
                . 'browser will reject at registration.',
                $scope
            ));

            return;
        }

        $this->ok(sprintf('Service worker scope is %s', $scope));
    }

    /**
     * The manifest `id` is a URL fragment identifier, and a fragment is a bug.
     *
     * The existing `id is empty` check is the common case; this one catches a value
     * that is set but broken — a hash, a query string, a same-origin URL the user meant
     * to paste into `start_url`. Set once and never change, and the spec treats it as a
     * string with that property.
     */
    private function checkManifestId(Config $config): void
    {
        $id = (string) $config->get('pwax.manifest.id', '');

        if ($id === '') {
            // Empty id is already covered by `checkManifest`.
            return;
        }

        if (str_contains($id, '#')) {
            $this->fail_(sprintf(
                'pwax.manifest.id (%s) contains a fragment. The Web App Manifest spec '
                . 'treats `id` as an opaque identifier, not a URL.',
                $id
            ));

            return;
        }

        if (str_contains($id, '?')) {
            $this->warn_(sprintf(
                'pwax.manifest.id (%s) contains a query string. Most browsers do not strip '
                . 'it, and an installed app will be keyed on the whole string.',
                $id
            ));
        }

        $this->ok(sprintf('Manifest id is %s', $id));
    }

    /**
     * `display` value the browser will accept for an install prompt.
     *
     * `standalone`, `fullscreen` and `minimal-ui` are the only installable values in
     * Chromium. Anything else is allowed by the manifest spec but the install prompt
     * silently refuses to appear, and the developer only finds out when a user reports
     * that they cannot install the app.
     */
    private function checkDisplayMode(Config $config): void
    {
        $display = (string) $config->get('pwax.manifest.display', '');

        if ($display === '') {
            return;
        }

        $installable = ['standalone', 'fullscreen', 'minimal-ui'];

        if (in_array($display, $installable, true)) {
            $this->ok(sprintf('Manifest display is %s', $display));

            return;
        }

        $this->warn_(sprintf(
            'pwax.manifest.display is "%s". Browsers offer an install prompt only for '
            . 'standalone, fullscreen and minimal-ui.',
            $display
        ));
    }

    /**
     * Service worker source maps are debug-only artifacts.
     *
     * The map file reveals the entire unminified source, which sometimes includes
     * comments, sometimes secrets left in dev. With the service worker that target
     * is every visitor of the application, not just the developer holding DevTools.
     */
    private function checkWorkerSourceMap(Config $config): void
    {
        if (! $config->get('pwax.service_worker.source_maps', false)) {
            return;
        }

        if ($this->laravel->environment('production')) {
            $this->warn_(
                'pwax.service_worker.source_maps is true in production. The source map is '
                . 'served publicly and reveals the unminified service worker to anyone who '
                . 'asks for it.'
            );

            return;
        }

        $this->ok('Service worker source maps are enabled in a non-production environment');
    }

    /**
     * VAPID is configured but there is no `push_subscriptions` table to write to.
     *
     * `php artisan pwax:push-endpoint` scaffolds the controller only; the schema is
     * the application's decision. A doctor run that wires push end-to-end and stops
     * only on the missing table is the same doctor that prevents the "subscribed
     * cleanly but the server 500s on every push" failure mode.
     */
    private function checkPushSubscriptionsTable(Config $config): void
    {
        if (! $config->get('pwax.push.public_key')) {
            return;
        }

        $connection = $config->get('database.default');

        $this->assert(
            $this->laravel->make('db')->connection($connection)
                ->getSchemaBuilder()->hasTable('push_subscriptions'),
            'push_subscriptions table exists',
            'pwax.push.public_key is set but the database has no `push_subscriptions` '
                . 'table. The endpoint from `pwax:push-endpoint` will throw on every write.'
        );
    }

    /**
     * SSR checks.
     *
     * When SSR is off — the default — there is nothing to check. When it is on, the one
     * thing that breaks silently is a missing or mismatched `@vue/server-renderer`: the
     * Node bridge fails, the `Prerenderer` falls back to the SPA shell, and the page
     * works but is not prerendered — exactly the regression the doctor exists to name.
     */
    private function checkSsr(Config $config): void
    {
        if (! $config->get('pwax.ssr.enabled', false)) {
            return;
        }

        // The same file the `Prerenderer` will run, `ssr.script` included — checking the
        // package's own bridge while the application runs another one is a clean bill of
        // health for something that was never going to be executed.
        $script = app(Prerenderer::class)->scriptPath();
        $node = (string) ($config->get('pwax.ssr.node') ?: 'node');

        if (! is_file($script)) {
            $this->fail_(sprintf('SSR is enabled but the bridge script was not found at %s.', $script));

            return;
        }

        // Run the bridge with an empty payload and ask it to report its dependencies.
        // The bridge writes `{ok: false, message: …}` to stdout when a peer dep is
        // missing or mismatched, and `{ok: true, …}` when both resolve.
        $process = new Process(
            [$node, $script],
            base_path(),
            null,
            (string) json_encode([
                'version' => $config->get('pwax.assets.versions.vue', ''),
                'component' => ['template' => '<div></div>'],
                'data' => [],
            ], JSON_THROW_ON_ERROR),
            10,
        );

        try {
            $process->run();
        } catch (Throwable $e) {
            $this->fail_(sprintf('SSR is enabled but Node could not start (%s): %s', $node, $e->getMessage()));

            return;
        }

        try {
            /** @var array<string, mixed> $result */
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // The bridge reports its own problems as JSON on stdout, so reaching here means
            // Node itself died — an unresolvable import, a syntax error, a missing script.
            // That goes to stderr, and reporting only the (empty) stdout is how a missing
            // `vue` package used to surface as "Node output:" followed by nothing at all.
            $error = trim($process->getErrorOutput());

            $this->fail_(sprintf(
                'SSR bridge did not return JSON. %s',
                $error !== ''
                    ? 'Node said: ' . $this->firstLine($error)
                    : 'Node wrote nothing to stdout or stderr.'
            ));

            return;
        }

        if (($result['ok'] ?? false) !== true) {
            $this->fail_(sprintf('SSR is enabled but the bridge failed: %s', (string) ($result['message'] ?? 'Unknown error.')));

            return;
        }

        $this->ok(sprintf(
            'SSR bridge renders (vue, @vue/server-renderer and @vue/compiler-dom at %s)',
            (string) $config->get('pwax.assets.versions.vue', '?')
        ));

        $fallback = (string) $config->get('pwax.ssr.fallback', 'spa');

        $this->assert(
            $fallback === 'spa',
            'SSR fallback is "spa" (a Node failure serves the SPA shell)',
            sprintf('SSR fallback is "%s" — a Node failure will return a 500. Set ssr.fallback to "spa" for production.', $fallback),
        );
    }

    /**
     * The document-head settings that are silently wrong rather than broken.
     *
     * Every check here concerns a tag nobody in the team ever looks at. A missing social
     * card is discovered when someone shares a link; a `noindex` left on after launch is
     * discovered when the traffic does not arrive; `hreflang` pointing at a relative path
     * is discovered never, because the crawler simply ignores it. None of them produce an
     * error, a warning in the console, or a visible difference in the browser — which is
     * exactly the category `pwax:doctor` exists for.
     *
     * The keys checked here are also how a `config/pwax.php` published before they existed
     * learns that they do: an application that never sets `head.image` is told once, in the
     * same run that tells it about everything else.
     */
    private function checkHead(Config $config): void
    {
        // Counted rather than tracked by hand, so the summary line below cannot fall out of
        // step with the checks when another one is added.
        $before = $this->warnings;

        $robots = $config->get('pwax.head.robots');

        // Said out loud because it applies to every page at once and reads, in a config
        // file, exactly like a value someone left behind after testing.
        if (is_string($robots) && str_contains(strtolower($robots), 'noindex')) {
            $this->warn_(sprintf(
                'head.robots is "%s" — every page carries it. Correct for staging, and the '
                . 'reason a launched site has no traffic if it survives the deploy.',
                $robots
            ));
        }

        $image = $config->get('pwax.head.image');

        if (! is_string($image) || $image === '') {
            $this->warn_(
                'head.image is not set. A link to this app unfurls with no image on every '
                . 'platform that reads Open Graph. Point it at a 1200x630 PNG.'
            );
        }

        foreach ((array) $config->get('pwax.head.alternates', []) as $hreflang => $href) {
            if (! is_string($href) || $href === '') {
                $this->warn_(sprintf('head.alternates["%s"] has no URL.', (string) $hreflang));
            }
        }

        $jsonLd = $config->get('pwax.head.json_ld');

        // `@context` is what makes the rest of the document mean anything; without it a
        // consumer has a bag of strings rather than a description of a thing.
        if (is_array($jsonLd) && $jsonLd !== [] && ! array_is_list($jsonLd) && ! isset($jsonLd['@context'])) {
            $this->warn_(
                'head.json_ld declares no @context. Add "@context" => "https://schema.org" '
                . 'or the structured data is ignored.'
            );
        }

        if ($this->warnings === $before) {
            $this->ok('Document head is configured for sharing and indexing');
        }
    }

    /**
     * The one line of a Node stack trace that says what went wrong.
     *
     * Not the first non-empty line: an uncaught throw puts the offending file and line
     * number there, then the source, then a caret, and only then the message. For a missing
     * package that meant reporting `node:internal/modules/package_json_reader:314` — a true
     * statement about Node's internals and no help at all — instead of
     * `Error [ERR_MODULE_NOT_FOUND]: Cannot find package 'vue' …`. So the error line is
     * sought first, and the first non-empty line is the fallback.
     */
    private function firstLine(string $output): string
    {
        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/', $output) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        foreach ($lines as $line) {
            if (preg_match('/^[A-Za-z]*(Error|Exception)\b/', $line) === 1) {
                return $line;
            }
        }

        return $lines[0] ?? $output;
    }
}
