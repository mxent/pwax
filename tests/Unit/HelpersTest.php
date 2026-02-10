<?php

namespace Mxent\Pwax\Tests\Unit;

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    /**
     * Test that vueParseTemplate correctly extracts template content
     */
    public function test_vue_parse_template_extracts_template()
    {
        $input = '<template><div>Hello World</div></template>';
        $expected = '<div>Hello World</div>';
        
        $result = vueParseTemplate($input);
        
        $this->assertEquals($expected, $result);
    }

    /**
     * Test that vueParseTemplate handles nested templates correctly
     */
    public function test_vue_parse_template_handles_nested_templates()
    {
        $input = '<template><div><template>Nested</template></div></template>';
        // The inner template tags are part of the content and should be extracted as well
        $expected = '<div>Nested</div>';
        
        $result = vueParseTemplate($input);
        
        // Note: The actual implementation recursively removes nested <template> tags
        $this->assertEquals($expected, $result);
    }

    /**
     * Test that vueParseTemplate returns empty string when no template found
     */
    public function test_vue_parse_template_returns_empty_when_no_template()
    {
        $input = '<div>No template tags here</div>';
        
        $result = vueParseTemplate($input);
        
        $this->assertEquals('', $result);
    }

    /**
     * Test that router helper generates correct path
     */
    public function test_router_helper_returns_path()
    {
        // Default behavior: when route() fails, should return '/'
        $result = router('nonexistent.route');
        $this->assertEquals('/', $result);
    }

    /**
     * Test that import helper generates correct syntax
     */
    public function test_import_helper_generates_correct_syntax()
    {
        $result = import('components.hello');
        
        $this->assertStringContainsString('await window.pwaxImport', $result);
        $this->assertStringContainsString('ComponentsHello', $result);
    }

    /**
     * Test that import helper handles "from" syntax
     */
    public function test_import_helper_handles_from_syntax()
    {
        $result = import('MyComponent from components.modal');
        
        $this->assertStringContainsString('await window.pwaxImport', $result);
        $this->assertStringContainsString('ComponentsModal', $result);
        $this->assertStringContainsString('MyComponent', $result);
    }
}
