<?php

namespace Mxent\Pwax\Tests\Unit;

use Mxent\Pwax\Exceptions\InvalidComponentId;
use Mxent\Pwax\Support\ComponentId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ComponentIdTest extends TestCase
{
    private ComponentId $ids;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ids = new ComponentId('test-application-key');
    }

    public function test_round_trips_a_view_name(): void
    {
        $this->assertSame('components.modal', $this->ids->decode($this->ids->encode('components.modal')));
    }

    public function test_round_trips_a_namespaced_view_name(): void
    {
        $this->assertSame('pwax::layouts.shell', $this->ids->decode($this->ids->encode('pwax::layouts.shell')));
    }

    public function test_encoding_is_deterministic(): void
    {
        $this->assertSame($this->ids->encode('a.b'), $this->ids->encode('a.b'));
    }

    public function test_identifiers_are_url_safe(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]+$/',
            $this->ids->encode('vendor::package.deeply.nested.view')
        );
    }

    /**
     * A scheme that encoded "." as "_x_" would make a view genuinely containing "_x_"
     * unreachable, and let two different views collide on one identifier.
     */
    public function test_a_view_name_containing_underscores_survives(): void
    {
        $this->assertSame('foo_x_bar', $this->ids->decode($this->ids->encode('foo_x_bar')));
    }

    public function test_rejects_a_tampered_payload(): void
    {
        $id = $this->ids->encode('components.modal');

        $this->expectException(InvalidComponentId::class);
        $this->ids->decode('Z' . substr($id, 1));
    }

    public function test_rejects_a_tampered_signature(): void
    {
        $id = $this->ids->encode('components.modal');
        $signature = substr($id, -ComponentId::SIGNATURE_LENGTH);
        $flipped = ($signature[0] === 'a' ? 'b' : 'a') . substr($signature, 1);

        $this->expectException(InvalidComponentId::class);
        $this->ids->decode(substr($id, 0, -ComponentId::SIGNATURE_LENGTH) . $flipped);
    }

    /**
     * The security property that matters: an identifier minted elsewhere must not work
     * here, so no one can hand-craft one for a view the server never exposed.
     */
    public function test_rejects_an_identifier_signed_with_another_key(): void
    {
        $foreign = (new ComponentId('a-different-key'))->encode('config.database');

        $this->expectException(InvalidComponentId::class);
        $this->ids->decode($foreign);
    }

    public function test_rejects_an_unsigned_view_name(): void
    {
        $this->expectException(InvalidComponentId::class);
        $this->ids->decode('components.modal');
    }

    #[DataProvider('malformedIdentifiers')]
    public function test_rejects_malformed_identifiers(string $id): void
    {
        $this->expectException(InvalidComponentId::class);
        $this->ids->decode($id);
    }

    public static function malformedIdentifiers(): array
    {
        return [
            'empty' => [''],
            'too short' => ['abc'],
            'signature only' => ['0123456789abcdef'],
            'non hex signature' => ['Y29tcG9uZW50cwZZZZZZZZZZZZZZZZZZ'],
            'illegal characters' => ['../../etc/passwd0123456789abcdef'],
        ];
    }

    public function test_verify_reports_without_throwing(): void
    {
        $this->assertTrue($this->ids->verify($this->ids->encode('a.b')));
        $this->assertFalse($this->ids->verify('not-a-real-identifier'));
    }

    public function test_an_empty_key_is_refused_at_construction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/key:generate/');

        new ComponentId('');
    }

    #[DataProvider('validViewNames')]
    public function test_accepts_valid_view_names(string $name): void
    {
        $this->assertSame($name, ComponentId::validate($name));
    }

    public static function validViewNames(): array
    {
        return [
            ['components.hello'],
            ['vendor::pkg.view'],
            ['my-component.sub-view'],
            ['a'],
            ['a1.b2'],
        ];
    }

    #[DataProvider('invalidViewNames')]
    public function test_rejects_invalid_view_names(string $name): void
    {
        $this->expectException(InvalidComponentId::class);
        ComponentId::validate($name);
    }

    public static function invalidViewNames(): array
    {
        return [
            'empty' => [''],
            'traversal' => ['../etc/passwd'],
            'traversal mid-name' => ['a..b'],
            'slash' => ['foo/bar'],
            'backslash' => ['foo\\bar'],
            'null byte' => ["foo\0bar"],
            'lone colon' => ['foo:bar'],
            'leading dot' => ['.hidden'],
            'trailing dot' => ['trailing.'],
            'space' => ['foo bar'],
            'php tag' => ['<?php'],
            'overlong' => [str_repeat('a', 256)],
        ];
    }
}
