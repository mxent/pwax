<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Mxent\Pwax\Pwa\AssetManifest;
use Mxent\Pwax\Pwa\ComponentRegistry;
use Mxent\Pwax\Pwa\Strategy;
use Mxent\Pwax\Pwa\WebManifest;
use Mxent\Pwax\Support\Shell;

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
    ) {
        parent::__construct();
    }

    public function handle(Config $config): int
    {
        $this->components->info('Checking your Pwax installation');

        $this->checkAppKey($config);
        $this->checkRemovedConfig($config);
        $this->checkDirective($config);
        $this->checkAssets($config);
        $this->checkCrossOriginPolicy($config);
        $this->checkRuntimeBundle();
        $this->checkManifest($config);
        $this->checkServiceWorker($config);
        $this->checkPrecache($config);
        $this->checkRouting($config);

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
     * Strategy names from before the vocabularies were merged.
     *
     * These still work and are meant to keep working for this major cycle, so each is a
     * warning rather than a problem. Naming them is the point: a config that says
     * `freshness` in one place and `network-first` in another describes the same behaviour
     * twice and reads like it describes two.
     */
    private function checkStrategyNames(Config $config): void
    {
        $keys = [
            'pwax.service_worker.runtime_strategy',
            'pwax.service_worker.navigation_strategy',
            'pwax.service_worker.pages.strategy',
        ];

        foreach ((array) $config->get('pwax.service_worker.data_groups', []) as $index => $group) {
            if (is_array($group) && Strategy::isDeprecated($group['strategy'] ?? null)) {
                $this->warn_(sprintf(
                    'Data group "%s" uses the old strategy name "%s". It still works; the current '
                        . 'name is "%s".',
                    is_string($group['name'] ?? null) ? $group['name'] : (string) $index,
                    $group['strategy'],
                    Strategy::ALIASES[strtolower(trim((string) $group['strategy']))],
                ));
            }
        }

        foreach ($keys as $key) {
            $value = $config->get($key);

            if (Strategy::isDeprecated($value)) {
                $this->warn_(sprintf(
                    '%s uses the old strategy name "%s". It still works; the current name is "%s".',
                    $key,
                    $value,
                    Strategy::ALIASES[strtolower(trim((string) $value))],
                ));
            }
        }

        if ($config->get('pwax.assets.source') === null && $config->get('pwax.assets.strategy') !== null) {
            $this->warn_(
                'pwax.assets.strategy is now pwax.assets.source. It chooses where the framework '
                . 'is served from, not how anything is cached, and the old name put a fifth '
                . '"strategy" key in a config where the others choose a caching behaviour.'
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

        $path = public_path(trim((string) $config->get('pwax.assets.local_path', '/vendor/pwax'), '/') . '/vue.global.prod.js');

        $this->assert(
            is_file($path),
            'Vue is published locally',
            sprintf('%s is missing. Run `php artisan vendor:publish --tag=pwax-assets`.', $path)
        );
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
        } catch (\Throwable $e) {
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
     * State a fact. Unlike `assert()` there is no failing branch — this is for things
     * worth showing that cannot be wrong.
     */
    private function ok(string $message): void
    {
        $this->components->twoColumnDetail($message, '<fg=green>OK</>');
    }
}
