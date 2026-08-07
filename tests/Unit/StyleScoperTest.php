<?php

namespace Mxent\Pwax\Tests\Unit;

use Mxent\Pwax\Compiler\StyleScoper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StyleScoperTest extends TestCase
{
    private StyleScoper $scoper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scoper = new StyleScoper;
    }

    #[DataProvider('selectors')]
    public function test_scopes_selectors(string $css, string $expected): void
    {
        $this->assertSame($expected, $this->scoper->scope($css, 'v1'));
    }

    public static function selectors(): array
    {
        return [
            'class' => ['.a{color:red}', '.a[data-pwax-v1]{color:red}'],
            'element' => ['div{color:red}', 'div[data-pwax-v1]{color:red}'],
            'selector list' => ['.a,.b{color:red}', '.a[data-pwax-v1],.b[data-pwax-v1]{color:red}'],

            // The attribute belongs on the element that is matched, not on its ancestors.
            'descendant' => ['.a .b{color:red}', '.a .b[data-pwax-v1]{color:red}'],
            'child combinator' => ['.a > .b{color:red}', '.a > .b[data-pwax-v1]{color:red}'],

            // `.a:hover[data-x]` would not match; the qualifier has to precede the pseudo.
            'pseudo class' => ['.a:hover{color:red}', '.a[data-pwax-v1]:hover{color:red}'],
            'pseudo element' => ['.a::before{content:""}', '.a[data-pwax-v1]::before{content:""}'],

            'attribute selector' => ['a[href^="/x"]{color:red}', 'a[href^="/x"][data-pwax-v1]{color:red}'],
            'comma inside :is()' => [':is(.a,.b) .c{color:red}', ':is(.a,.b) .c[data-pwax-v1]{color:red}'],
            'empty stylesheet' => ['', ''],
        ];
    }

    #[DataProvider('atRules')]
    public function test_handles_at_rules(string $css, string $expected): void
    {
        $this->assertSame($expected, $this->scoper->scope($css, 'v1'));
    }

    public static function atRules(): array
    {
        return [
            'media recurses into its body' => [
                '@media (min-width:600px){.a{color:red}}',
                '@media (min-width:600px){.a[data-pwax-v1]{color:red}}',
            ],
            'supports recurses' => [
                '@supports (display:grid){.a{display:grid}}',
                '@supports (display:grid){.a[data-pwax-v1]{display:grid}}',
            ],

            // Scoping `0%` would silently break the animation.
            'keyframes are left alone' => [
                '@keyframes spin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}',
                '@keyframes spin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}',
            ],
            'font-face is left alone' => [
                '@font-face{font-family:X;src:url(x.woff2)}',
                '@font-face{font-family:X;src:url(x.woff2)}',
            ],
            'import statement is left alone' => [
                '@import url("x.css");.a{color:red}',
                '@import url("x.css");.a[data-pwax-v1]{color:red}',
            ],
        ];
    }

    public function test_deep_escapes_the_scope(): void
    {
        $this->assertSame(
            '.a[data-pwax-v1] .b{color:red}',
            $this->scoper->scope('.a :deep(.b){color:red}', 'v1')
        );
    }

    public function test_global_bypasses_scoping_entirely(): void
    {
        $this->assertSame('.b{color:red}', $this->scoper->scope(':global(.b){color:red}', 'v1'));
    }

    public function test_scope_ids_differ_per_component(): void
    {
        $this->assertNotSame(
            $this->scoper->scopeIdFor('pages.a', '.x{}'),
            $this->scoper->scopeIdFor('pages.b', '.x{}')
        );
    }

    public function test_scope_ids_are_stable(): void
    {
        $this->assertSame(
            $this->scoper->scopeIdFor('pages.a', '.x{}'),
            $this->scoper->scopeIdFor('pages.a', '.x{}')
        );
    }

    public function test_preserves_comments(): void
    {
        $this->assertStringContainsString('/* keep me */', $this->scoper->scope('/* keep me */.a{color:red}', 'v1'));
    }

    public function test_a_brace_inside_a_string_does_not_break_parsing(): void
    {
        $this->assertSame(
            '.a[data-pwax-v1]::after{content:"}"}',
            $this->scoper->scope('.a::after{content:"}"}', 'v1')
        );
    }
}
