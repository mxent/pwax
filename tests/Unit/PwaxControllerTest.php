<?php

namespace Mxent\Pwax\Tests\Unit;

use Mxent\Pwax\Http\Controllers\PwaxController;
use Mxent\Pwax\Tests\TestCase;
use ReflectionClass;

class PwaxControllerTest extends TestCase
{
    protected function invokePrivate(object $object, string $method, array $args = [])
    {
        $ref = new ReflectionClass($object);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($object, $args);
    }

    public function test_resolve_view_name_decodes_dots(): void
    {
        $controller = new PwaxController();
        $this->assertSame(
            'components.hello',
            $this->invokePrivate($controller, 'resolveViewName', ['components_x_hello'])
        );
    }

    public function test_resolve_view_name_decodes_namespaces(): void
    {
        $controller = new PwaxController();
        $this->assertSame(
            'vendor::package.view',
            $this->invokePrivate($controller, 'resolveViewName', ['vendor__x__package_x_view'])
        );
    }

    public function test_resolve_view_name_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $controller = new PwaxController();
        $this->invokePrivate($controller, 'resolveViewName', ['bad/name']);
    }
}
