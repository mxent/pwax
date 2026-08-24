<?php

namespace Mxent\Pwax\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mxent\Pwax\Contracts\Minifier;
use Mxent\Pwax\Minification\CachedMinifier;
use Mxent\Pwax\Minification\MatthiasMullieMinifier;
use Mxent\Pwax\Minification\NullMinifier;
use PHPUnit\Framework\TestCase;

class MinifierTest extends TestCase
{
    public function test_the_null_minifier_is_a_pass_through(): void
    {
        $minifier = new NullMinifier;

        $this->assertSame('const a = 1;', $minifier->js('const a = 1;'));
        $this->assertSame('.a { color: red }', $minifier->css('.a { color: red }'));
    }

    public function test_minifies_javascript(): void
    {
        $result = (new MatthiasMullieMinifier)->js("const a = 1;\n\nconst b = 2;\n");

        $this->assertStringNotContainsString("\n\n", $result);
        $this->assertStringContainsString('const a=1', $result);
    }

    public function test_minifies_css(): void
    {
        $this->assertSame('.a{color:red}', (new MatthiasMullieMinifier)->css(".a {\n    color: red;\n}\n"));
    }

    public function test_blank_sources_stay_blank(): void
    {
        $minifier = new MatthiasMullieMinifier;

        $this->assertSame('', $minifier->js("  \n "));
        $this->assertSame('', $minifier->css(''));
    }

    /**
     * `matthiasmullie/minify` is regex-based, so it can mis-handle syntax it was not
     * written for. Whatever it does, the contract is that a component still runs:
     * output is never empty and never larger than the input it was given.
     */
    public function test_never_destroys_a_source_it_cannot_handle(): void
    {
        $minifier = new MatthiasMullieMinifier;

        $sources = [
            'const re = /<\/script>/g; export default { re };',
            'const t = `a ${b > c ? "}" : "{"} d`; export default { t };',
            'export default { async *gen() { yield await Promise.resolve(1); } };',
            'const x = a?.b?.[c] ?? d; export default { x };',
            'class A { #p = 1; get p() { return this.#p; } } export default A;',
        ];

        foreach ($sources as $source) {
            $result = $minifier->js($source);

            $this->assertNotSame('', trim($result), 'Minifier emptied: ' . $source);
            $this->assertLessThanOrEqual(strlen($source), strlen($result), 'Minifier grew: ' . $source);
        }
    }

    public function test_the_cached_minifier_only_calls_through_once(): void
    {
        $inner = new CountingMinifier;
        $cached = new CachedMinifier($inner, new Repository(new ArrayStore));

        $this->assertSame('js:a', $cached->js('a'));
        $this->assertSame('js:a', $cached->js('a'));

        $this->assertSame(1, $inner->jsCalls);
    }

    public function test_the_cached_minifier_keys_on_content(): void
    {
        $inner = new CountingMinifier;
        $cached = new CachedMinifier($inner, new Repository(new ArrayStore));

        $cached->css('a');
        $cached->css('b');

        $this->assertSame(2, $inner->cssCalls);
    }

    public function test_the_cached_minifier_keeps_js_and_css_apart(): void
    {
        $inner = new CountingMinifier;
        $cached = new CachedMinifier($inner, new Repository(new ArrayStore));

        $this->assertSame('js:x', $cached->js('x'));
        $this->assertSame('css:x', $cached->css('x'));
    }

    public function test_the_cached_minifier_short_circuits_blank_input(): void
    {
        $inner = new CountingMinifier;
        $cached = new CachedMinifier($inner, new Repository(new ArrayStore));

        $this->assertSame('', $cached->js('   '));
        $this->assertSame(0, $inner->jsCalls);
    }
}

class CountingMinifier implements Minifier
{
    public int $jsCalls = 0;

    public int $cssCalls = 0;

    public function js(string $source): string
    {
        $this->jsCalls++;

        return 'js:' . $source;
    }

    public function css(string $source): string
    {
        $this->cssCalls++;

        return 'css:' . $source;
    }
}
