<?php

namespace Mxent\Pwax\Tests\Unit;

use Mxent\Pwax\Pwa\Glob;
use PHPUnit\Framework\TestCase;

/**
 * Angular's glob syntax, compiled to a regular expression.
 *
 * These patterns decide what an application precaches, so getting one subtly wrong is
 * either a file missing offline or — for the deny list — a `.env.backup` handed to the
 * browser as a URL to go and fetch. `fnmatch()` is not used precisely because its `**`
 * handling varies by platform; that makes these cases the specification.
 */
class GlobTest extends TestCase
{
    /**
     * @return list<array{string, string, bool}>
     */
    public static function patterns(): array
    {
        return [
            'a star stays inside one segment' => ['/*.js', '/app.js', true],
            'a star does not cross a separator' => ['/*.js', '/a/b.js', false],
            'a double star crosses separators' => ['/images/**', '/images/deep/b.png', true],
            'a double star still anchors' => ['/images/**', '/other/a.png', false],
            'a double star inside a segment crosses too' => ['/css/**.css', '/css/vendor/x.css', true],
            'and still respects the extension' => ['/css/**.css', '/css/app.js', false],
            'a middle double star may match nothing' => ['/a/**/b', '/a/b', true],
            'or several segments' => ['/a/**/b', '/a/x/y/b', true],
            'brace alternation' => ['/**.{jpg,jpeg}', '/deep/a.jpeg', true],
            'pipe alternation' => ['/**.(svg|png)', '/x/logo.svg', true],
            'alternation excludes what it does not list' => ['/**.(svg|png)', '/x/logo.gif', false],
            'a question mark is one character' => ['/?.js', '/a.js', true],
            'and only one' => ['/?.js', '/ab.js', false],
            'a dot is literal' => ['/a.js', '/axjs', false],
            'dotfiles are reachable' => ['/**/.*', '/a/.env', true],
        ];
    }

    /**
     * @dataProvider patterns
     */
    public function test_it_matches_the_way_angular_does(string $glob, string $path, bool $expected): void
    {
        $this->assertSame($expected, Glob::matches($glob, $path));
    }

    public function test_an_empty_rule_list_matches_everything(): void
    {
        // "No opinion", not "nothing" — an unconfigured navigation list must not stop the
        // worker claiming navigations.
        $this->assertTrue(Glob::matchesAny([], '/anything'));
    }

    public function test_a_negated_rule_excludes(): void
    {
        $rules = ['/**', '!/**/*.*'];

        $this->assertTrue(Glob::matchesAny($rules, '/dashboard'));
        $this->assertFalse(Glob::matchesAny($rules, '/reports/2024.pdf'));
    }

    public function test_a_path_no_positive_rule_claims_does_not_match(): void
    {
        $this->assertFalse(Glob::matchesAny(['/admin/**'], '/dashboard'));
    }

    /**
     * The default `navigation_urls`, which decide whether the worker answers a navigation
     * at all. Getting these wrong replaces somebody's Horizon dashboard with the SPA.
     */
    public function test_the_default_navigation_rules(): void
    {
        $rules = ['/**', '!/**/*.*', '!/**/*__*', '!/**/*__*/**'];

        foreach (['/', '/dashboard', '/posts/1/edit'] as $path) {
            $this->assertTrue(Glob::matchesAny($rules, $path), $path . ' should be the application');
        }

        foreach (['/favicon.ico', '/reports/2024.pdf', '/__pwax__/shell'] as $path) {
            $this->assertFalse(Glob::matchesAny($rules, $path), $path . ' should bypass the worker');
        }
    }

    public function test_compiled_rules_keep_their_negation(): void
    {
        $compiled = Glob::compile(['/**', '!/**/*.*']);

        $this->assertStringStartsWith('^', $compiled[0]);
        $this->assertStringStartsWith('!^', $compiled[1]);
    }

    public function test_an_unclosed_brace_is_treated_as_a_literal(): void
    {
        // Configuration, not code: refusing to build the whole manifest over one stray
        // brace is a poor trade against matching one file fewer than intended.
        $this->assertTrue(Glob::matches('/a{b', '/a{b'));
    }
}
