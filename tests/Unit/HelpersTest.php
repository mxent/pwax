<?php

namespace Mxent\Pwax\Tests\Unit;

use Mxent\Pwax\Tests\TestCase;

class HelpersTest extends TestCase
{
    public function test_vue_parse_template_extracts_outer_template(): void
    {
        $this->assertSame(
            '<div>Hello World</div>',
            vueParseTemplate('<template><div>Hello World</div></template>')
        );
    }

    public function test_vue_parse_template_keeps_inner_tags(): void
    {
        $result = vueParseTemplate('<template><div><template>Nested</template></div></template>');
        $this->assertStringContainsString('Nested', $result);
        $this->assertStringStartsWith('<div>', $result);
    }

    public function test_vue_parse_template_returns_empty_when_missing(): void
    {
        $this->assertSame('', vueParseTemplate('<div>No template</div>'));
    }

    public function test_pwax_extract_blocks_finds_multiple(): void
    {
        $html = "<script>a()</script><script lang=\"js\">b()</script>";
        $blocks = pwaxExtractBlocks('script', $html);
        $this->assertCount(2, $blocks);
        $this->assertSame('a()', $blocks[0]);
        $this->assertSame('b()', $blocks[1]);
    }

    public function test_pwax_extract_blocks_ignores_src_scripts(): void
    {
        $html = '<script src="/foo.js"></script><script>inline()</script>';
        $blocks = pwaxExtractBlocks('script', $html);
        $this->assertSame(['inline()'], $blocks);
    }

    public function test_pwax_validate_view_name_accepts_valid_names(): void
    {
        $this->assertSame('components.hello', pwaxValidateViewName('components.hello'));
        $this->assertSame('vendor::pkg.view', pwaxValidateViewName('vendor::pkg.view'));
    }

    public function test_pwax_validate_view_name_rejects_path_traversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        pwaxValidateViewName('../etc/passwd');
    }

    public function test_pwax_validate_view_name_rejects_slashes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        pwaxValidateViewName('foo/bar');
    }

    public function test_router_returns_path_for_named_route(): void
    {
        $this->assertSame('/about', router('pwax.test.about'));
    }

    public function test_router_returns_absolute_when_requested(): void
    {
        $this->assertSame('http://localhost/about', router('pwax.test.about', [], true));
    }

    public function test_router_falls_back_to_home_for_unknown(): void
    {
        // Unknown route -> fallback to configured home route name.
        $this->assertSame('/', router('definitely.does.not.exist'));
    }

    public function test_import_helper_emits_pwax_import_call(): void
    {
        $result = import('components.hello');
        $this->assertStringContainsString('await window.pwaxImport(', $result);
        $this->assertStringContainsString('"ComponentsHello"', $result);
    }

    public function test_import_helper_supports_from_syntax(): void
    {
        $result = import('MyModal from components.modal');
        $this->assertStringContainsString('"ComponentsModal"', $result);
        $this->assertStringContainsString('"MyModal"', $result);
    }
}
