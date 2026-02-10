<?php

namespace Mxent\Pwax\Tests\Unit;

use Mxent\Pwax\Http\Controllers\PwaxController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PwaxControllerTest extends TestCase
{
    protected PwaxController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new PwaxController();
    }

    /**
     * Test that validateViewName accepts valid names
     */
    public function test_validate_view_name_accepts_valid_names()
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateViewName');
        $method->setAccessible(true);

        // Valid names should pass
        $validNames = [
            'components_x_hello',
            'views_x_profile',
            'namespace__x__views_x_component',
            'simple-component',
            'component_with_underscore',
        ];

        foreach ($validNames as $name) {
            $result = $method->invoke($this->controller, $name);
            $this->assertIsString($result);
            $this->assertStringNotContainsString('..', $result);
        }
    }

    /**
     * Test that validateViewName rejects path traversal attempts
     */
    public function test_validate_view_name_rejects_path_traversal()
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateViewName');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path traversal detected');

        // Attempt path traversal: ../../../config would be encoded as .._x_.._x_.._x_config
        // After decoding _x_ to ., this becomes ../../config
        $method->invoke($this->controller, '.._x_.._x_config');
    }

    /**
     * Test that validateViewName rejects invalid characters
     */
    public function test_validate_view_name_rejects_invalid_characters()
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateViewName');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid view name format');

        $method->invoke($this->controller, 'component/with/slashes');
    }

    /**
     * Test that validateViewName correctly decodes view names
     */
    public function test_validate_view_name_decodes_correctly()
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateViewName');
        $method->setAccessible(true);

        $input = 'components_x_hello';
        $result = $method->invoke($this->controller, $input);
        
        $this->assertEquals('components.hello', $result);
    }

    /**
     * Test that validateViewName handles namespace separators
     */
    public function test_validate_view_name_handles_namespaces()
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('validateViewName');
        $method->setAccessible(true);

        $input = 'vendor__x__package_x_view';
        $result = $method->invoke($this->controller, $input);
        
        $this->assertEquals('vendor::package.view', $result);
    }
}
