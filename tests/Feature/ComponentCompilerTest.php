<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Compiler\ComponentCompiler;
use Mxent\Pwax\Exceptions\InvalidComponentId;
use Mxent\Pwax\Tests\TestCase;

class ComponentCompilerTest extends TestCase
{
    private function compiler(): ComponentCompiler
    {
        return $this->app->make(ComponentCompiler::class);
    }

    public function test_compiles_a_component_view(): void
    {
        $component = $this->compiler()->compile('pages.home');

        $this->assertStringContainsString('<h1>{{ title }}</h1>', $component->template);
        $this->assertStringContainsString('export default', $component->script);
        $this->assertStringContainsString('.home', $component->style);
    }

    public function test_blade_escaping_leaves_vue_interpolation_intact(): void
    {
        // `@{{ title }}` in the source must reach the browser as `{{ title }}`.
        $this->assertStringContainsString('{{ title }}', $this->compiler()->compile('pages.home')->template);
    }

    public function test_passes_view_data_through(): void
    {
        $component = $this->compiler()->compile('pages.with-data', ['name' => 'Ada']);

        $this->assertStringContainsString('Hello Ada', $component->template);
        $this->assertStringContainsString('"Ada"', $component->script);
    }

    public function test_separates_external_assets_from_inline_blocks(): void
    {
        $component = $this->compiler()->compile('pages.assets');

        $this->assertSame(['https://cdn.example.test/chart.css'], $component->externalStyles);
        $this->assertSame(['https://cdn.example.test/chart.js'], $component->externalScripts);
        $this->assertStringContainsString("name: 'Chart'", $component->script);
    }

    public function test_scoped_styles_are_rewritten_and_the_template_stamped(): void
    {
        $component = $this->compiler()->compile('pages.scoped');

        $this->assertNotNull($component->scopeId);
        $this->assertStringContainsString('[data-pwax-' . $component->scopeId . ']', $component->style);
        $this->assertStringContainsString('data-pwax-' . $component->scopeId, $component->template);
    }

    public function test_unscoped_styles_leave_the_template_untouched(): void
    {
        $component = $this->compiler()->compile('pages.home');

        $this->assertNull($component->scopeId);
        $this->assertStringNotContainsString('data-pwax-', $component->template);
    }

    /**
     * The headline 1.x bug: a Blade directive named `import` also matched the CSS
     * at-rule and replaced it with JavaScript.
     */
    public function test_a_css_at_import_rule_survives_compilation(): void
    {
        $component = $this->compiler()->compile('pages.css-import');

        $this->assertStringContainsString('@import', $component->style);
        $this->assertStringContainsString('fonts.example.test/inter.css', $component->style);
        $this->assertStringNotContainsString('pwaxImport', $component->style);
        $this->assertStringNotContainsString('window.pwax', $component->style);
    }

    public function test_the_import_directive_resolves_at_render_time(): void
    {
        $component = $this->compiler()->compile('pages.imports');

        $this->assertStringContainsString('window.pwax.component', $component->script);
        $this->assertStringContainsString($this->id('components.modal'), $component->script);
    }

    public function test_rejects_an_invalid_view_name(): void
    {
        $this->expectException(InvalidComponentId::class);

        $this->compiler()->compile('../../etc/passwd');
    }

    public function test_compiling_the_same_output_twice_is_consistent(): void
    {
        $first = $this->compiler()->compile('pages.home');
        $second = $this->compiler()->compile('pages.home');

        $this->assertSame($first->hash(), $second->hash());
        $this->assertSame($first->template, $second->template);
    }

    public function test_different_view_data_produces_different_output(): void
    {
        $a = $this->compiler()->compile('pages.with-data', ['name' => 'Ada']);
        $b = $this->compiler()->compile('pages.with-data', ['name' => 'Grace']);

        $this->assertNotSame($a->hash(), $b->hash());
        $this->assertStringContainsString('Grace', $b->template);
    }

    public function test_compile_source_parses_supplied_markup(): void
    {
        $component = $this->compiler()->compileSource(
            'inline',
            '<template><b>hi</b></template><script>export default {}</script>'
        );

        $this->assertSame('<b>hi</b>', $component->template);
    }
}
