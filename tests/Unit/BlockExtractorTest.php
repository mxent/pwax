<?php

namespace Mxent\Pwax\Tests\Unit;

use Mxent\Pwax\Compiler\BlockExtractor;
use PHPUnit\Framework\TestCase;

class BlockExtractorTest extends TestCase
{
    private BlockExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new BlockExtractor;
    }

    public function test_extracts_the_template_body(): void
    {
        $this->assertSame(
            '<div>Hello</div>',
            $this->extractor->template('<template><div>Hello</div></template>')
        );
    }

    public function test_extracts_a_template_with_attributes(): void
    {
        $this->assertSame('<p>A</p>', $this->extractor->template('<template lang="html"><p>A</p></template>'));
    }

    public function test_returns_empty_when_there_is_no_template(): void
    {
        $this->assertSame('', $this->extractor->template('<div>no template here</div>'));
    }

    /**
     * 1.x took the first `<template>` and the last `</template>`, which broke as soon as
     * a component used Vue's own structural templates.
     */
    public function test_matches_the_closing_tag_by_depth(): void
    {
        $this->assertSame(
            '<div><template v-if="x">Y</template></div>',
            $this->extractor->template('<template><div><template v-if="x">Y</template></div></template>')
        );
    }

    public function test_ignores_a_template_string_inside_a_script(): void
    {
        $this->assertSame(
            '<b>real</b>',
            $this->extractor->template(
                '<script>const s = "<template>fake</template>";</script><template><b>real</b></template>'
            )
        );
    }

    public function test_ignores_a_self_closing_nested_template(): void
    {
        $this->assertSame(
            '<template /><i>z</i>',
            $this->extractor->template('<template><template /><i>z</i></template>')
        );
    }

    public function test_collects_every_inline_script(): void
    {
        $blocks = $this->extractor->scripts('<script>a()</script><script lang="js">b()</script>');

        $this->assertCount(2, $blocks);
        $this->assertSame('a()', $blocks[0]['content']);
        $this->assertSame('js', $blocks[1]['attributes']['lang']);
    }

    public function test_skips_scripts_that_reference_a_source(): void
    {
        $blocks = $this->extractor->scripts('<script src="/x.js"></script><script>inline()</script>');

        $this->assertCount(1, $blocks);
        $this->assertSame('inline()', $blocks[0]['content']);
    }

    public function test_skips_empty_blocks(): void
    {
        $this->assertSame([], $this->extractor->scripts("<script>\n   \n</script>"));
    }

    public function test_valueless_attributes_become_true(): void
    {
        $blocks = $this->extractor->scripts('<script setup>const a = 1;</script>');

        $this->assertTrue($blocks[0]['attributes']['setup']);
    }

    public function test_detects_scoped_styles(): void
    {
        $blocks = $this->extractor->styles('<style scoped>.a{}</style><style>.b{}</style>');

        $this->assertTrue($blocks[0]['attributes']['scoped']);
        $this->assertArrayNotHasKey('scoped', $blocks[1]['attributes']);
    }

    public function test_collects_external_script_sources(): void
    {
        $this->assertSame(
            ['/a.js', '/b.js'],
            $this->extractor->externalScripts('<script src="/a.js"></script><script src=\'/b.js\'></script>')
        );
    }

    public function test_decodes_entities_in_sources(): void
    {
        $this->assertSame(
            ['/a.js?x=1&y=2'],
            $this->extractor->externalScripts('<script src="/a.js?x=1&amp;y=2"></script>')
        );
    }

    public function test_deduplicates_sources(): void
    {
        $this->assertSame(
            ['/a.js'],
            $this->extractor->externalScripts('<script src="/a.js"></script><script src="/a.js"></script>')
        );
    }

    /**
     * 1.x matched every `<link href>`, so the manifest link and favicons were handed to
     * the client as component stylesheets.
     */
    public function test_only_stylesheet_links_are_collected(): void
    {
        $this->assertSame(
            ['/a.css'],
            $this->extractor->externalStyles(
                '<link rel="manifest" href="/m.json">'
                . '<link rel="stylesheet" href="/a.css">'
                . '<link rel="icon" href="/favicon.ico">'
                . '<link rel="preload" as="font" href="/f.woff2">'
            )
        );
    }

    public function test_css_at_rules_pass_through_untouched(): void
    {
        $css = '@import url("https://fonts.example/x.css");@media (min-width:1px){.a{color:red}}';

        $this->assertSame($css, $this->extractor->styles('<style>' . $css . '</style>')[0]['content']);
    }

    public function test_parses_attributes_of_a_start_tag(): void
    {
        $attributes = $this->extractor->parseAttributes('<script type="module" defer src=\'/a.js\'>');

        $this->assertSame('module', $attributes['type']);
        $this->assertTrue($attributes['defer']);
        $this->assertSame('/a.js', $attributes['src']);
    }
}
