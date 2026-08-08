<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Mxent\Pwax\Tests\TestCase;

/**
 * An application that renamed the import directive.
 *
 * Blade directives are registered once, while the provider boots, so the name has to be
 * set before the application starts rather than inside a test — which is why this is its
 * own class rather than one more case in BladeDirectiveTest.
 */
class RenamedDirectiveTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pwax.components.directive', 'component');
    }

    public function test_the_configured_name_compiles(): void
    {
        $this->assertStringContainsString(
            'Pwax::import(',
            Blade::compileString("@component('components.modal')")
        );
    }

    /**
     * One name, not two. The configured name replaces the default rather than joining it,
     * so there is exactly one spelling in an application and no question about which one
     * a given view is using.
     */
    public function test_the_default_name_is_not_also_registered(): void
    {
        $this->assertStringNotContainsString(
            'Pwax::import(',
            Blade::compileString("@pwaxImport('components.modal')")
        );
    }

    /**
     * The reason the name is validated at all: Blade matches a directive even with no
     * arguments, so one called `import` would also capture the CSS at-rule.
     */
    public function test_a_css_at_import_rule_is_still_left_alone(): void
    {
        $css = '<style>@import url("https://fonts.example/inter.css");.a{color:red}</style>';

        $this->assertSame($css, Blade::compileString($css));
    }
}
