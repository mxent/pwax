<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwax;
use Mxent\Pwax\Support\Shell;
use Mxent\Pwax\Tests\TestCase;

/**
 * The PHP and JS sides of the runtime agree on what crosses between them.
 *
 * The PHP side is the source of truth in both cases here. The JS side is a
 * hand-typed mirror — two header names in `src/js/http.js`, and the shape of
 * the config island in `types/pwax.d.ts` — and a mirror is exactly the kind of
 * thing that drifts when someone updates one side and not the other.
 *
 * Both failures are silent. A header name that stops matching means a request
 * is no longer recognised as a component fetch and is served the SPA shell,
 * which then collides with itself. A config key missing from the type is a key
 * with no documented shape at all, which is how three of them went unlisted.
 */
class RuntimeContractTest extends TestCase
{
    public function test_the_js_side_mirrors_the_php_constants(): void
    {
        $js = $this->runtimeSource('src/js/http.js');

        $this->assertStringContainsString(
            "export const COMPONENT_HEADER = '" . Pwax::HEADER . "'",
            $js,
            'JS `COMPONENT_HEADER` no longer matches `Pwax::HEADER`. Update both sides together.'
        );

        $this->assertStringContainsString(
            "export const LOCATION_HEADER = '" . Pwax::LOCATION_HEADER . "'",
            $js,
            'JS `LOCATION_HEADER` no longer matches `Pwax::LOCATION_HEADER`. Update both sides together.'
        );
    }

    /**
     * Every key `runtimeConfig()` sends is declared in the typed contract, and vice versa.
     *
     * `types/pwax.d.ts` is what an application's TypeScript reads and what CI type-checks,
     * so it is the published shape of `window.pwax.config`. Nothing but this test connects
     * it to the method that actually builds the object — the two are different languages in
     * different directories — and the last time they diverged, `plugins`, `directives` and
     * `middleware` were absent from the type for as long as they had existed.
     */
    public function test_the_typed_config_matches_the_one_the_server_builds(): void
    {
        $declared = $this->configInterfaceKeys();
        $sent = array_keys($this->app->make(Shell::class)->runtimeConfig());

        sort($declared);
        sort($sent);

        $this->assertSame(
            $declared,
            $sent,
            'types/pwax.d.ts `Config` and `Shell::runtimeConfig()` disagree. '
            . 'Adding a runtime config key means adding it to both.'
        );
    }

    /**
     * The property names declared on the `Config` interface.
     *
     * Parsed rather than reflected, because the file is TypeScript. The interface body runs
     * to its closing brace at the start of a line, which is what the block below relies on —
     * a nested object type is written inline (`push: { … }`) and never spans lines, so no
     * brace counting is needed.
     *
     * @return list<string>
     */
    private function configInterfaceKeys(): array
    {
        $types = $this->runtimeSource('types/pwax.d.ts');

        $start = strpos($types, 'interface Config {');

        $this->assertNotFalse($start, 'types/pwax.d.ts no longer declares an interface named Config.');

        $end = strpos($types, "\n    }", $start);

        $this->assertNotFalse($end, 'The Config interface in types/pwax.d.ts is not closed as expected.');

        $body = substr($types, $start, $end - $start);

        // A property line, ignoring the docblocks and comments between them.
        preg_match_all('/^\s{8}([A-Za-z_][A-Za-z0-9_]*)\??:/m', $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Read the runtime source for a file in `src/`.
     */
    private function runtimeSource(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;

        $this->assertFileExists($path, "Runtime source missing: {$relative}");

        return (string) file_get_contents($path);
    }
}
