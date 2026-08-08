<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Mxent\Pwax\Pwa\AssetManifest;
use Mxent\Pwax\Pwa\ComponentRegistry;
use Mxent\Pwax\Pwax;
use Throwable;

/**
 * Shows exactly what the service worker will install for offline use.
 *
 * "Works offline" is otherwise a claim nobody can check: the asset manifest is generated,
 * the worker consumes it in the background, and the only way to find out that a component
 * was left out is for a user to reach it with no connection. This prints the manifest the
 * worker would receive, and `--verify` renders every component so a view that cannot be
 * served without controller data is discovered here rather than in the field.
 */
class PrecacheCommand extends Command
{
    protected $signature = 'pwax:precache
        {--json : Print the asset manifest as JSON}
        {--verify : Render every selected component and report the ones that fail}';

    protected $description = 'List everything the service worker will cache for offline use';

    public function handle(
        AssetManifest $manifest,
        ComponentRegistry $registry,
        Config $config,
        Pwax $pwax,
    ): int {
        // Always built fresh: a memoised copy from a minute ago is not what you want to
        // look at when you are trying to work out why a change has not appeared.
        $manifest->flush();
        $built = $manifest->build();

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $built,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        if (! $config->get('pwax.service_worker.enabled', false)) {
            $this->components->warn(
                'The service worker is disabled, so none of this is cached yet. '
                . 'Set pwax.service_worker.enabled to true.'
            );
        }

        $this->components->twoColumnDetail('<fg=gray>Manifest hash</>', (string) ($built['hash'] ?? ''));
        $this->components->twoColumnDetail('<fg=gray>Version</>', (string) ($built['version'] ?? ''));
        $this->newLine();

        $total = 0;

        /** @var list<array{name: string, urls: list<string>}> $groups */
        $groups = $built['assetGroups'] ?? [];

        /** @var array<string, string> $hashes */
        $hashes = $built['hashTable'] ?? [];

        foreach ($groups as $group) {
            $urls = $group['urls'];
            $total += count($urls);

            $this->components->info(sprintf('%s (%d)', $group['name'], count($urls)));

            foreach ($urls as $url) {
                $this->components->twoColumnDetail(
                    $url,
                    isset($hashes[$url]) ? '<fg=gray>' . $hashes[$url] . '</>' : '<fg=yellow>unhashed</>'
                );
            }

            $this->newLine();
        }

        $this->components->info(sprintf('%d URL(s) will be available offline.', $total));

        $all = count($registry->all());
        $selected = count($registry->precachable());

        if ($all !== $selected) {
            $this->components->warn(sprintf(
                '%d of %d components are excluded by pwax.service_worker.components '
                . 'and will only be cached after they have been loaded online.',
                $all - $selected,
                $all
            ));
        }

        return $this->option('verify') ? $this->verify($registry, $pwax) : self::SUCCESS;
    }

    /**
     * Render every selected component and report the ones that will not precache.
     *
     * A page rendered with controller data cannot be served from its view name alone, so
     * requesting its module URL renders it with nothing bound and it throws. That entry
     * simply fails at install time — the worker tolerates it — but the developer should
     * know, because it means that page is not actually available offline.
     */
    private function verify(ComponentRegistry $registry, Pwax $pwax): int
    {
        $this->newLine();
        $this->components->info('Rendering each component');

        $failed = [];

        foreach ($registry->precachable() as $component) {
            try {
                $pwax->compile($component['view']);
            } catch (Throwable $e) {
                $failed[$component['view']] = $e->getMessage();
            }
        }

        if ($failed === []) {
            $this->components->info('Every selected component renders without controller data.');

            return self::SUCCESS;
        }

        foreach ($failed as $view => $message) {
            $this->components->twoColumnDetail($view, '<fg=red>' . mb_strimwidth($message, 0, 80, '…') . '</>');
        }

        $this->newLine();
        $this->components->error(sprintf(
            '%d component(s) cannot be rendered without controller data and will not be '
            . 'precached. Exclude them with pwax.service_worker.components.',
            count($failed)
        ));

        return self::FAILURE;
    }
}
