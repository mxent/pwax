<?php

namespace Mxent\Pwax\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The vendored frontend builds, their pinned versions and their integrity hashes.
 *
 * Three places have to agree about Vue, Vue Router and Pinia: the files in
 * `resources/vendor/`, the versions and `sha384` hashes in `config/pwax.php`, and the
 * table in `resources/vendor/README.md`. Nothing enforces that agreement at runtime,
 * because the two halves are never used together — `assets.source => 'local'` serves the
 * files and ignores the hashes, `'cdn'` sends the hashes and ignores the files.
 *
 * Which is exactly why it rots. Someone updates Vue, replaces the vendored build, and
 * forgets the hash; nothing fails until an application that happens to run in CDN mode
 * deploys and the browser refuses to execute the script it just downloaded. The failure
 * surfaces in an application, at the worst moment, a release after the mistake.
 *
 * These assertions move that discovery to the commit that causes it.
 */
class VendoredAssetsTest extends TestCase
{
    /**
     * Package name (as `assets.versions` keys it) => vendored filename.
     *
     * @return list<array{string, string}>
     */
    public static function assets(): array
    {
        return [
            'vue, full build' => ['vue', 'vue.global.prod.js'],
            // Keyed by filename in the integrity map, because `vue` ships two builds and
            // one hash cannot cover both.
            'vue, runtime-only build' => ['vue.runtime.global.prod.js', 'vue.runtime.global.prod.js'],
            'vue-router' => ['vue-router', 'vue-router.global.prod.js'],
            'pinia' => ['pinia', 'pinia.iife.prod.js'],
        ];
    }

    #[DataProvider('assets')]
    public function test_the_configured_integrity_hash_matches_the_vendored_build(string $key, string $file): void
    {
        $path = self::vendorPath($file);

        $this->assertFileExists($path, sprintf(
            '%s is listed in config/pwax.php but is not vendored. Publishing pwax-assets would '
            . 'produce an application that cannot load its own framework.',
            $file
        ));

        $expected = 'sha384-' . base64_encode((string) hash_file('sha384', $path, true));

        $this->assertSame($expected, self::integrity()[$key] ?? null, sprintf(
            'The sha384 in pwax.assets.cdn.integrity for "%s" does not match resources/vendor/%s. '
            . 'Regenerate it — a stale hash makes every browser in CDN mode refuse to run the '
            . 'script, and nothing in local mode ever notices. See resources/vendor/README.md.',
            $key,
            $file
        ));
    }

    /**
     * Every vendored file is accounted for, so adding one without pinning it fails here
     * rather than shipping unversioned and unhashed.
     */
    public function test_every_vendored_build_is_pinned_and_hashed(): void
    {
        $vendored = array_map(
            'basename',
            (array) glob(self::vendorPath('*.js'))
        );

        sort($vendored);

        $listed = array_column(self::assets(), 1);
        sort($listed);

        $this->assertSame($listed, $vendored);
    }

    /**
     * The README table is the only documentation of what is vendored, and a version it
     * names that config does not is a reader sent to the wrong release notes.
     */
    #[DataProvider('versions')]
    public function test_the_readme_documents_the_pinned_version(string $package, string $version): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/vendor/README.md');

        $this->assertStringContainsString('| ' . $version . ' |', $readme, sprintf(
            'resources/vendor/README.md does not list %s %s, which is what config/pwax.php pins.',
            $package,
            $version
        ));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function versions(): array
    {
        $out = [];

        foreach (self::config()['assets']['versions'] as $package => $version) {
            $out[$package] = [$package, (string) $version];
        }

        return array_values($out);
    }

    /**
     * @return array<string, string>
     */
    private static function integrity(): array
    {
        /** @var array<string, string> $hashes */
        $hashes = self::config()['assets']['cdn']['integrity'];

        return $hashes;
    }

    /**
     * The published config, read as a plain array.
     *
     * `require`d rather than resolved through an application: this file is one array
     * literal, and standing up Testbench to read it would make a unit test a feature one.
     *
     * It does call `env()`, which Illuminate's helpers provide and Composer autoloads
     * before PHPUnit runs — so it is always there in practice. Checked anyway, because
     * "undefined function env()" from inside a `require` is a considerably worse way to
     * learn that than being told.
     *
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        if (! function_exists('env')) {
            throw new \RuntimeException(
                'config/pwax.php calls env(), which is not defined. It comes from Illuminate\'s '
                . 'helpers via Composer\'s autoloader — run `composer install`.'
            );
        }

        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2) . '/config/pwax.php';

        return $config;
    }

    private static function vendorPath(string $file): string
    {
        return dirname(__DIR__, 2) . '/resources/vendor/' . $file;
    }
}
