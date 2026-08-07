<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

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

    public function handle(Config $config): int
    {
        $this->components->info('Checking your Pwax installation');

        $this->checkAppKey($config);
        $this->checkDirective($config);
        $this->checkAssets($config);
        $this->checkRuntimeBundle();
        $this->checkManifest($config);
        $this->checkServiceWorker($config);
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

    private function checkAppKey(Config $config): void
    {
        $this->assert(
            (string) $config->get('app.key', '') !== '',
            'APP_KEY is set',
            'APP_KEY is empty. Component identifiers cannot be signed. Run `php artisan key:generate`.'
        );
    }

    private function checkDirective(Config $config): void
    {
        $directive = (string) $config->get('pwax.components.directive', 'pwax');

        $this->assert(
            $directive !== 'import',
            sprintf('Import directive is @%s', $directive),
            'pwax.components.directive is "import", which also matches the CSS at-rule @import '
            . 'inside <style> blocks and rewrites it as JavaScript. Change it to "pwax".'
        );
    }

    private function checkAssets(Config $config): void
    {
        $strategy = (string) $config->get('pwax.assets.strategy', 'local');

        if ($strategy === 'cdn') {
            $this->warn_(
                'Assets load from a CDN. The app cannot start offline, and every visitor\'s IP '
                . 'is disclosed to the CDN. Set pwax.assets.strategy to "local" and run '
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

        /** @var list<array{sizes?: string}> $icons */
        $icons = $manifest['icons'] ?? [];
        $sizes = array_map(static fn (array $i): string => (string) ($i['sizes'] ?? ''), $icons);

        // Chromium requires both to offer an install prompt.
        foreach (['192x192', '512x512'] as $required) {
            $this->assert(
                in_array($required, $sizes, true),
                sprintf('Manifest has a %s icon', $required),
                sprintf('pwax.manifest.icons has no %s entry; browsers will not offer to install the app.', $required)
            );
        }
    }

    private function checkServiceWorker(Config $config): void
    {
        if (! $config->get('pwax.service_worker.enabled', false)) {
            $this->warn_('The service worker is disabled, so the app will not work offline.');

            return;
        }

        $path = (string) $config->get('pwax.service_worker.path', '/service-worker.js');

        $this->assert(
            substr_count(trim($path, '/'), '/') === 0,
            'Service worker is served from the root',
            sprintf(
                'The service worker is served from %s. A worker can only control paths at or below '
                . 'its own URL, so serve it from the root to cover the whole site.',
                $path
            )
        );
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
}
