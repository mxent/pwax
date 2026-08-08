<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Mxent\Pwax\Tests\TestCase;

/**
 * An application that kept the 2.x directive name.
 *
 * Blade directives are registered once, while the provider boots, so this has to be set
 * before the application starts rather than inside a test — which is why it is its own
 * class rather than one more case in BladeDirectiveTest.
 */
class RenamedDirectiveTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pwax.components.directive', 'pwax');
    }

    /**
     * Both names work at once, and Blade must not read the shorter as a prefix of the
     * longer.
     *
     * Blade captures the maximal run of word characters after `@` before looking the
     * directive up, so this should hold — but the failure mode is a compiled view
     * containing a stray literal `Import('b')`, which is worth asserting rather than
     * assuming. It is what lets a published view and a freshly generated one sit in the
     * same codebase during a migration.
     */
    public function test_both_the_renamed_and_canonical_directives_compile(): void
    {
        $compiled = Blade::compileString("@pwax('a') @pwaxImport('b')");

        $this->assertSame(2, substr_count($compiled, 'Pwax::import('));
        $this->assertStringNotContainsString("Import('b')", $compiled);
    }

    /**
     * The reason the directive name is validated at all: Blade matches a directive even
     * with no arguments, so one called `import` also captures the CSS at-rule.
     */
    public function test_a_css_at_import_rule_is_still_left_alone(): void
    {
        $css = '<style>@import url("https://fonts.example/inter.css");.a{color:red}</style>';

        $this->assertSame($css, Blade::compileString($css));
    }
}
