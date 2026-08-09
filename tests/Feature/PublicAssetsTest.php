<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Mxent\Pwax\Pwa\PublicAssets;
use Mxent\Pwax\Tests\TestCase;

/**
 * What a glob is allowed to sweep out of `public/`.
 *
 * A precache entry is a URL the browser is instructed to go and fetch and keep, so the
 * deny list is doing real work: an application that writes a bare recursive glob in an
 * asset group is relying on it to be the thing that stops `.env.backup` becoming a cached
 * URL.
 *
 * Hidden paths have two defences and these assert the outcome rather than either one. The
 * directory walk skips them first — `allFiles()` asks Finder to ignore dot files, and that
 * covers dot *directories* too. The deny list is the second, and until now it only
 * described a hidden leaf: it named `/.env` and said nothing about `/.git/config`, where
 * the file is not hidden and its directory is. Nothing reached that gap, because nothing
 * got past the walk — which is exactly the kind of protection that is worth having
 * written down correctly before the walk changes.
 */
class PublicAssetsTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->public = sys_get_temp_dir() . '/pwax-public-' . bin2hex(random_bytes(6));

        $this->write('css/app.css', 'body{}');
        $this->write('images/logo.svg', '<svg/>');
        $this->write('.env', 'APP_KEY=secret');
        $this->write('.git/config', '[core]');
        $this->write('vendor/.git/config', '[core]');
        $this->write('.well-known/security.txt', 'Contact: x');
        $this->write('build/app.php', '<?php');

        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->public);

        parent::tearDown();
    }

    public function test_it_finds_ordinary_assets(): void
    {
        $urls = $this->urls(['/**']);

        $this->assertContains('/css/app.css', $urls);
        $this->assertContains('/images/logo.svg', $urls);
    }

    public function test_a_hidden_file_is_never_precached(): void
    {
        $this->assertNotContains('/.env', $this->urls(['/**']));
    }

    public function test_a_file_inside_a_hidden_directory_is_never_precached(): void
    {
        $urls = $this->urls(['/**']);

        // The file is not hidden. Its directory is, and everything below a hidden
        // directory is as private as the directory itself.
        $this->assertNotContains('/.git/config', $urls);
        $this->assertNotContains('/vendor/.git/config', $urls);
    }

    public function test_well_known_is_not_precached(): void
    {
        // Those files are read by an operating system or a certificate authority, never
        // by the application through its worker, so precaching them buys nothing — and
        // leaving a file out here does not stop the server serving it.
        $this->assertNotContains('/.well-known/security.txt', $this->urls(['/**']));
    }

    public function test_php_files_are_never_precached(): void
    {
        $this->assertNotContains('/build/app.php', $this->urls(['/**']));
    }

    public function test_an_empty_pattern_list_matches_nothing(): void
    {
        // "No groups configured" must mean no precaching, not everything.
        $this->assertSame([], $this->urls([]));
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private function urls(array $patterns): array
    {
        /** @var PublicAssets $assets */
        $assets = $this->app->make(PublicAssets::class);

        return array_column($assets->match($patterns), 'url');
    }

    private function write(string $path, string $contents): void
    {
        $full = $this->public . '/' . $path;

        (new Filesystem)->ensureDirectoryExists(dirname($full));
        file_put_contents($full, $contents);
    }
}
