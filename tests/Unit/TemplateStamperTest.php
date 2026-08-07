<?php

namespace Mxent\Pwax\Tests\Unit;

use Mxent\Pwax\Compiler\TemplateStamper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TemplateStamperTest extends TestCase
{
    private TemplateStamper $stamper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stamper = new TemplateStamper;
    }

    #[DataProvider('templates')]
    public function test_stamps_elements(string $template, string $expected): void
    {
        $this->assertSame($expected, $this->stamper->stamp($template, 'v1'));
    }

    public static function templates(): array
    {
        return [
            'single element' => ['<div>x</div>', '<div data-pwax-v1>x</div>'],
            'nested' => ['<div><p>x</p></div>', '<div data-pwax-v1><p data-pwax-v1>x</p></div>'],
            'siblings' => ['<i></i><b></b>', '<i data-pwax-v1></i><b data-pwax-v1></b>'],
            'kebab component' => ['<my-comp></my-comp>', '<my-comp data-pwax-v1></my-comp>'],
            'attributes preserved' => [
                '<a href="/x" @click="go">y</a>',
                '<a href="/x" @click="go" data-pwax-v1>y</a>',
            ],
            'empty' => ['', ''],
        ];
    }

    /**
     * Vue's template parser accepts self-closing custom elements; an HTML parser does
     * not, and would treat everything after `<MyComp />` as its children. That is why
     * this is a character scanner rather than DOMDocument.
     */
    public function test_preserves_self_closing_components(): void
    {
        $this->assertSame('<MyComp data-pwax-v1 />', $this->stamper->stamp('<MyComp />', 'v1'));
        $this->assertSame('<MyComp data-pwax-v1 />', $this->stamper->stamp('<MyComp/>', 'v1'));
    }

    public function test_a_greater_than_inside_an_attribute_does_not_end_the_tag(): void
    {
        $this->assertSame(
            '<div :class="{ on: a > b }" data-pwax-v1>x</div>',
            $this->stamper->stamp('<div :class="{ on: a > b }">x</div>', 'v1')
        );
    }

    public function test_handles_single_quoted_attributes(): void
    {
        $this->assertSame(
            "<div :title='a > b' data-pwax-v1>x</div>",
            $this->stamper->stamp("<div :title='a > b'>x</div>", 'v1')
        );
    }

    /**
     * `<template>` and `<slot>` render no element of their own, so stamping them would
     * attach the scope to nothing.
     */
    public function test_skips_tags_that_render_no_element(): void
    {
        $this->assertSame(
            '<template v-if="a"><p data-pwax-v1>x</p></template>',
            $this->stamper->stamp('<template v-if="a"><p>x</p></template>', 'v1')
        );

        $this->assertSame('<slot name="a"></slot>', $this->stamper->stamp('<slot name="a"></slot>', 'v1'));
    }

    public function test_leaves_comments_alone(): void
    {
        $this->assertSame(
            '<!-- <div> --><p data-pwax-v1>x</p>',
            $this->stamper->stamp('<!-- <div> --><p>x</p>', 'v1')
        );
    }

    public function test_a_lone_less_than_in_text_is_not_a_tag(): void
    {
        $this->assertSame('<p data-pwax-v1>a < b</p>', $this->stamper->stamp('<p>a < b</p>', 'v1'));
    }
}
