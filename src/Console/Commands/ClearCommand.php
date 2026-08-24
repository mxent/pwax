<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Mxent\Pwax\Pwa\AssetManifest;

class ClearCommand extends Command
{
    protected $signature = 'pwax:clear';

    protected $description = 'Flush cached component compilations, minified sources and the offline manifest';

    public function handle(CacheFactory $cache, AssetManifest $assets): int
    {
        // Explicit, because unlike the component cache this one *can* be stale: it is
        // memoised on a TTL rather than on its own content, so a component added a moment
        // ago is missing from the offline manifest until it expires.
        $assets->flush();

        $stores = array_values(array_unique(array_filter([
            (string) config('pwax.cache.store', '') ?: null,
            (string) config('pwax.minify.store', '') ?: null,
        ])));

        // `null` means "the application default store", which is the common case.
        if ($stores === []) {
            $stores = [null];
        }

        foreach ($stores as $name) {
            $label = $name ?? 'default';

            try {
                // Pwax cache keys are content-addressed rather than tagged, so there is
                // no way to purge only ours. Flushing the whole store is acceptable here
                // because running this command is an explicit request to do exactly that.
                $cache->store($name)->clear();
            } catch (\Throwable $e) {
                $this->components->error(sprintf('Could not flush store [%s]: %s', $label, $e->getMessage()));

                return self::FAILURE;
            }

            $this->components->info(sprintf('Flushed cache store [%s].', $label));
        }

        $this->components->warn(
            'Compiled components are keyed by a digest of their rendered output, so they '
            . 'can never go stale — this command is only needed to reclaim space.'
        );

        return self::SUCCESS;
    }
}
