<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
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

        // A glob that pulled in more than the ceilings allow is truncated rather than
        // fatal, which is the right behaviour and a terrible thing to discover in
        // production. Say so here, where someone is already looking.
        /** @var list<string> $warnings */
        $warnings = $built['warnings'] ?? [];

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        $total = 0;

        /** @var list<array{name: string, urls: list<string>, kind?: string}> $groups */
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

        return $this->option('verify') ? $this->verify($registry, $pwax, $built) : self::SUCCESS;
    }

    /**
     * Render every selected component and request every page, and report the failures.
     *
     * A page rendered with controller data cannot be served from its view name alone, so
     * requesting its module URL renders it with nothing bound and it throws. That entry
     * simply fails at install time — the worker tolerates it — but the developer should
     * know, because it means that page is not actually available offline.
     *
     * Pages are tested by issuing a synthetic guest GET to each URL — the same request
     * shape the service worker uses at install time. A route that 5xx's here is one
     * that will 5xx offline for a first-time visitor with no warm cache; surfacing it
     * here is what makes the precache list a contract rather than a wish.
     *
     * @param  array<string, mixed>  $built
     */
    private function verify(ComponentRegistry $registry, Pwax $pwax, array $built): int
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

        $componentFailed = $failed !== [];

        if ($failed === []) {
            $this->components->info('Every selected component renders without controller data.');
        } else {
            foreach ($failed as $view => $message) {
                $this->components->twoColumnDetail($view, '<fg=red>' . mb_strimwidth($message, 0, 80, '…') . '</>');
            }

            $this->newLine();
            $this->components->error(sprintf(
                '%d component(s) cannot be rendered without controller data and will not be '
                . 'precached. Exclude them with pwax.service_worker.components.',
                count($failed)
            ));
        }

        $pageFailed = $this->verifyPages($built);

        return ($componentFailed || $pageFailed) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Issue a guest GET to every page the manifest will precache, and report 5xx.
     *
     * The request is built with the same `X-Pwax-Component` header the worker uses, so
     * a route that distinguishes between "guest who asked for a page" and "guest who
     * asked for a payload" still gets the right one. We do not assert the response
     * shape — only that the route is alive enough to answer.
     *
     * @param  array<string, mixed>  $built
     */
    private function verifyPages(array $built): bool
    {
        /** @var list<array<string, mixed>> $groups */
        $groups = $built['assetGroups'] ?? [];

        $pages = [];

        foreach ($groups as $group) {
            if (($group['kind'] ?? null) !== 'page') {
                continue;
            }

            foreach ((array) ($group['urls'] ?? []) as $url) {
                $pages[] = (string) $url;
            }
        }

        if ($pages === []) {
            return false;
        }

        $this->newLine();
        $this->components->info(sprintf('Probing %d page(s)', count($pages)));

        $kernel = $this->laravel->make(Kernel::class);
        $failures = [];

        foreach ($pages as $url) {
            $request = Request::create($url, 'GET');
            $request->headers->set('X-Pwax-Component', 'true');
            $request->headers->set('Accept', 'application/json');
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');

            try {
                $response = $kernel->handle($request);
                $status = $response->getStatusCode();
            } catch (Throwable $e) {
                $failures[$url] = $e->getMessage();
                $status = 0;
            }

            if (isset($failures[$url])) {
                $this->components->twoColumnDetail(
                    $url,
                    '<fg=red>threw: ' . mb_strimwidth($failures[$url], 0, 60, '…') . '</>'
                );

                continue;
            }

            if ($status >= 500) {
                $this->components->twoColumnDetail($url, '<fg=red>' . $status . '</>');
                $failures[$url] = 'status ' . $status;

                continue;
            }

            $this->components->twoColumnDetail($url, '<fg=green>OK</>');
        }

        if ($failures !== []) {
            $this->newLine();
            $this->components->error(sprintf(
                '%d page(s) failed to respond cleanly. A route that 5xxs here will fail offline '
                . 'for any visitor who has not opened it before.',
                count($failures)
            ));
        }

        return $failures !== [];
    }
}
