<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Mxent\Pwax\Compiler\ComponentCompiler;
use Mxent\Pwax\Facades\Pwax;
use Mxent\Pwax\Tests\TestCase;

/**
 * What the compiled-component cache is allowed to keep.
 *
 * The key is a digest of the *rendered output*, which is what makes an entry impossible
 * to serve staleley — a changed component simply produces a new key. For a component that
 * takes no data that is a clean win: one entry, every visitor, forever.
 *
 * For a page rendered with controller data it inverts. The output is particular to the
 * request, so keying on it mints an entry per visitor that nothing will ever read again —
 * written with `forever()`, on a store whose only purge is "flush everything". A busy
 * application filled Redis with them.
 */
class ComponentCacheTest extends TestCase
{
    /**
     * Cache keys the compiler has written.
     *
     * @return list<string>
     */
    private function entries(): array
    {
        $store = Cache::store()->getStore();

        $this->assertInstanceOf(ArrayStore::class, $store);

        return array_values(array_filter(
            array_keys($store->all()),
            static fn (string $key): bool => str_contains($key, 'pwax:component:')
        ));
    }

    /**
     * When a stored entry expires, as a unix timestamp. `0` means never.
     */
    private function expiryOf(string $key): float
    {
        $store = Cache::store()->getStore();

        $this->assertInstanceOf(ArrayStore::class, $store);

        return $store->all()[$key]['expiresAt'];
    }

    public function test_a_component_with_no_data_is_stored(): void
    {
        Pwax::compile('pages.home');

        $this->assertCount(1, $this->entries());
    }

    public function test_a_page_rendered_with_data_is_not_stored(): void
    {
        pwaxRender('pages.with-data', ['name' => 'Ada'])->component();
        pwaxRender('pages.with-data', ['name' => 'Grace'])->component();

        // The defect this test exists for: two visitors, two permanent entries, neither
        // of which the other could ever hit.
        $this->assertSame([], $this->entries());
    }

    public function test_cacheable_opts_a_page_with_data_back_in(): void
    {
        pwaxRender('pages.with-data', ['name' => 'Ada'])->cacheable()->component();

        // `cacheable()` is the developer saying this page renders the same for everyone —
        // a stronger claim than the compile cache needs, so it is taken as consent.
        $this->assertCount(1, $this->entries());
    }

    public function test_a_page_with_data_still_reads_the_cache(): void
    {
        // Refusing to *write* must not mean refusing to *read*. The key is the rendered
        // output, so an entry put there by anything at all is the right answer.
        Pwax::compile('pages.with-data', ['name' => 'Ada'], store: true);

        $stored = $this->entries();
        $this->assertCount(1, $stored);

        $again = pwaxRender('pages.with-data', ['name' => 'Ada'])->component();

        $this->assertStringContainsString('Ada', $again->template);
        $this->assertSame($stored, $this->entries());
    }

    public function test_entries_carry_the_configured_ttl(): void
    {
        config()->set('pwax.cache.ttl', 120);
        $this->refreshCompiler();

        Pwax::compile('pages.home');

        [$key] = $this->entries();

        // A float with sub-second precision, so bracket it rather than matching exactly.
        $this->assertGreaterThan(time() + 60, $this->expiryOf($key));
        $this->assertLessThan(time() + 121, $this->expiryOf($key));
    }

    public function test_a_null_ttl_stores_forever(): void
    {
        config()->set('pwax.cache.ttl', null);
        $this->refreshCompiler();

        Pwax::compile('pages.home');

        [$key] = $this->entries();

        // `ArrayStore::forever()` records an expiry of 0.
        $this->assertSame(0.0, (float) $this->expiryOf($key));
    }

    public function test_the_same_component_twice_in_one_request_reaches_the_store_once(): void
    {
        $spy = new class extends ArrayStore
        {
            public int $reads = 0;

            public function get($key): mixed
            {
                $this->reads++;

                return parent::get($key);
            }
        };

        Cache::extend('spy', fn (): mixed => Cache::repository($spy));
        config()->set('cache.stores.spy', ['driver' => 'spy']);
        config()->set('pwax.cache.store', 'spy');
        $this->refreshCompiler();

        Pwax::compile('pages.home');
        Pwax::compile('pages.home');

        // One store read, not two: the second call is answered from the in-process memo.
        // Two `@pwaxImport`s of one component, or a caller reading `toArray()` before
        // returning the response, used to pay a full round trip to Redis each time.
        $this->assertSame(1, $spy->reads);
    }

    /**
     * Drop the resolved compiler so the next resolution reads the config just set.
     */
    private function refreshCompiler(): void
    {
        $this->app->forgetInstance(ComponentCompiler::class);
        $this->app->forgetInstance(\Mxent\Pwax\Pwax::class);
        $this->app->forgetInstance('pwax');
        Pwax::clearResolvedInstance('pwax');
    }
}
