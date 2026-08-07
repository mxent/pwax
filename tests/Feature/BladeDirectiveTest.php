<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Mxent\Pwax\Facades\Pwax;
use Mxent\Pwax\PwaxServiceProvider;
use Mxent\Pwax\Tests\TestCase;

class BladeDirectiveTest extends TestCase
{
    public function test_the_directive_emits_php_rather_than_a_baked_url(): void
    {
        $compiled = Blade::compileString("@pwax('components.modal')");

        // The URL must be resolved when the view runs. In 1.x the directive returned its
        // final output, which froze the URL into storage/framework/views — so changing
        // route_prefix, or running view:cache before routes were loaded, broke imports.
        $this->assertStringContainsString('<?php echo', $compiled);
        $this->assertStringContainsString('importExpression', $compiled);
        $this->assertStringNotContainsString('window.pwax.component', $compiled);
    }

    /**
     * The defect this rename exists to fix. Blade matches `@name` even with no
     * arguments, so a directive called `import` also captured the CSS at-rule
     * `@import url(...)` in every <style> block in the application and replaced it
     * with JavaScript.
     */
    public function test_a_css_at_import_rule_is_left_alone(): void
    {
        $css = '<style>@import url("https://fonts.example/inter.css");.a{color:red}</style>';

        $this->assertSame($css, Blade::compileString($css));
    }

    public function test_other_css_at_rules_are_left_alone(): void
    {
        $css = '<style>@media (min-width:1px){.a{color:red}}@keyframes s{to{opacity:1}}@font-face{font-family:X}</style>';

        $this->assertSame($css, Blade::compileString($css));
    }

    public function test_resolves_a_component_to_a_synchronous_async_component(): void
    {
        $js = Pwax::importExpression("'components.modal'");

        // Synchronous, not awaited: two components that import each other would
        // deadlock at module top level under native ES modules if this awaited.
        $this->assertStringContainsString('window.pwax.component(', $js);
        $this->assertStringNotContainsString('await ', $js);
        $this->assertStringContainsString($this->id('components.modal'), $js);

        // The client resolves this with a dynamic import(), so it has to be the module
        // URL. A .json URL would be fetched as a module and fail to parse.
        $this->assertStringContainsString('.js"', $js);
        $this->assertStringNotContainsString('.json', $js);
    }

    public function test_supports_selecting_a_named_export(): void
    {
        $js = Pwax::importExpression("'Backdrop from components.modal'");

        $this->assertStringContainsString('"Backdrop"', $js);
        $this->assertStringContainsString($this->id('components.modal'), $js);
    }

    public function test_an_empty_expression_degrades_gracefully(): void
    {
        $this->assertStringContainsString('null', Pwax::importExpression(null));
        $this->assertStringContainsString('null', Pwax::importExpression(''));
    }

    public function test_the_emitted_url_is_json_encoded(): void
    {
        $js = Pwax::importExpression("'components.modal'");

        $this->assertMatchesRegularExpression('/window\.pwax\.component\("[^"]+", "[^"]*"\)/', $js);
    }

    public function test_naming_the_directive_import_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be "import"/');

        PwaxServiceProvider::assertDirectiveName('import');
    }

    public function test_a_malformed_directive_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PwaxServiceProvider::assertDirectiveName('not a name');
    }

    public function test_a_valid_directive_name_is_accepted(): void
    {
        $this->assertSame('vueImport', PwaxServiceProvider::assertDirectiveName('vueImport'));
    }
}
