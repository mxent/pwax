<?php

namespace Mxent\Pwax\Tests\Unit;

use Illuminate\Config\Repository;
use Mxent\Pwax\Pwa\Ssr\Prerenderer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The prerender memo is bounded.
 *
 * `Prerenderer` is registered as a singleton, so its memo lives as long as the container
 * does. Under `php artisan serve` that is one request and an unbounded memo is invisible;
 * under Octane, FrankenPHP or Swoole it is the worker's whole lifetime — and the entries
 * are rendered HTML documents plus their serialized state, one per distinct page, never
 * released. That is the deployment SSR is most likely to be running in, because it is the
 * one where not paying for a PHP bootstrap per request is the reason to be there.
 *
 * Driven through the private method rather than through `render()`: the eviction rule is
 * pure logic, and reaching it through `render()` would mean spawning Node once per entry
 * to prove something that has nothing to do with Node.
 */
class PrerenderMemoTest extends TestCase
{
    public function test_the_memo_does_not_grow_without_limit(): void
    {
        $prerenderer = new Prerenderer(new Repository);

        $size = $this->memoSize();

        for ($i = 0; $i < $size * 4; $i++) {
            $this->remember($prerenderer, "key-{$i}");
        }

        $this->assertCount($size, $this->memo($prerenderer));
    }

    public function test_the_memo_keeps_the_most_recently_written_entries(): void
    {
        $prerenderer = new Prerenderer(new Repository);

        $size = $this->memoSize();

        for ($i = 0; $i < $size + 3; $i++) {
            $this->remember($prerenderer, "key-{$i}");
        }

        // The first three are the ones that aged out. Evicting the *newest* would be worse
        // than not memoising at all: the page being rendered right now is the one whose
        // second read the memo exists to serve.
        $this->assertSame(
            array_map(static fn (int $i): string => "key-{$i}", range(3, $size + 2)),
            array_keys($this->memo($prerenderer))
        );
    }

    public function test_re_remembering_an_entry_does_not_grow_the_memo(): void
    {
        $prerenderer = new Prerenderer(new Repository);

        for ($i = 0; $i < 20; $i++) {
            $this->remember($prerenderer, 'the-same-page');
        }

        $this->assertCount(1, $this->memo($prerenderer));
    }

    public function test_remember_returns_the_result_it_was_given(): void
    {
        $prerenderer = new Prerenderer(new Repository);

        $this->assertSame(
            ['html' => '<p>key</p>', 'state' => '{}', 'styles' => []],
            $this->remember($prerenderer, 'key')
        );
    }

    /**
     * @return array{html: string, state: string, styles: array<string, string>}
     */
    private function remember(Prerenderer $prerenderer, string $key): array
    {
        $method = new ReflectionMethod($prerenderer, 'remember');

        /** @var array{html: string, state: string, styles: array<string, string>} $result */
        $result = $method->invoke($prerenderer, $key, [
            'html' => "<p>{$key}</p>",
            'state' => '{}',
            'styles' => [],
        ]);

        return $result;
    }

    /**
     * @return array<string, array{html: string, state: string, styles: array<string, string>}>
     */
    private function memo(Prerenderer $prerenderer): array
    {
        /** @var array<string, array{html: string, state: string, styles: array<string, string>}> $memo */
        $memo = (new ReflectionProperty($prerenderer, 'memo'))->getValue($prerenderer);

        return $memo;
    }

    private function memoSize(): int
    {
        return (int) (new \ReflectionClassConstant(Prerenderer::class, 'MEMO_SIZE'))->getValue();
    }
}
