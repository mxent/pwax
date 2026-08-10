<?php

namespace Mxent\Pwax\Tests\Feature;

use Mxent\Pwax\Pwax;
use Mxent\Pwax\Tests\TestCase;

/**
 * The PHP and JS sides of the runtime agree on two header names.
 *
 * The PHP side is the source of truth. The JS side is a hand-typed mirror in
 * `src/js/http.js`. The two could drift if someone updates one without the
 * other, and the failure is silent: a request stops being recognised as a
 * component fetch and starts being served the SPA shell, which then collides
 * with itself.
 */
class HeaderConstantsTest extends TestCase
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
     * Read the runtime source for a file in `src/`.
     */
    private function runtimeSource(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;

        $this->assertFileExists($path, "Runtime source missing: {$relative}");

        return file_get_contents($path);
    }
}
