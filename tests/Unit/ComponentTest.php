<?php

namespace Mxent\Pwax\Tests\Unit;

use Mxent\Pwax\Data\Component;
use PHPUnit\Framework\TestCase;

class ComponentTest extends TestCase
{
    public function test_exposes_the_wire_format(): void
    {
        $component = new Component(
            view: 'pages.home',
            template: '<div></div>',
            script: 'export default {}',
            style: '.a{}',
            externalStyles: ['/a.css'],
            externalScripts: ['/a.js'],
            scopeId: 'abc',
            id: 'signed-id',
        );

        $this->assertSame([
            'id' => 'signed-id',
            'hash' => $component->hash(),
            'scope' => 'abc',
            'template' => '<div></div>',
            'script' => 'export default {}',
            'style' => '.a{}',
            'styles' => ['/a.css'],
            'scripts' => ['/a.js'],
        ], $component->toArray());
    }

    public function test_the_hash_is_stable_for_identical_content(): void
    {
        $a = new Component(view: 'a', template: '<p></p>', script: 'x');
        $b = new Component(view: 'b', template: '<p></p>', script: 'x');

        // Keyed on content, not on the view name: two views rendering the same output
        // should share a cache entry and an ETag.
        $this->assertSame($a->hash(), $b->hash());
    }

    public function test_the_hash_changes_with_any_part(): void
    {
        $base = new Component(view: 'a', template: '<p></p>', script: 'x', style: '.a{}');

        $this->assertNotSame($base->hash(), (new Component(view: 'a', template: '<i></i>', script: 'x', style: '.a{}'))->hash());
        $this->assertNotSame($base->hash(), (new Component(view: 'a', template: '<p></p>', script: 'y', style: '.a{}'))->hash());
        $this->assertNotSame($base->hash(), (new Component(view: 'a', template: '<p></p>', script: 'x', style: '.b{}'))->hash());
        $this->assertNotSame(
            $base->hash(),
            (new Component(view: 'a', template: '<p></p>', script: 'x', style: '.a{}', scopeId: 'z'))->hash()
        );
    }

    public function test_reports_emptiness(): void
    {
        $this->assertTrue((new Component(view: 'a'))->isEmpty());
        $this->assertFalse((new Component(view: 'a', template: '<p></p>'))->isEmpty());
    }

    public function test_with_id_returns_a_copy(): void
    {
        $original = new Component(view: 'a', template: '<p></p>');
        $withId = $original->withId('xyz');

        $this->assertNull($original->id);
        $this->assertSame('xyz', $withId->id);
        $this->assertSame($original->hash(), $withId->hash());
    }

    public function test_serialises_to_json(): void
    {
        $json = json_decode((new Component(view: 'a', template: '<p></p>'))->toJson(), true);

        $this->assertSame('<p></p>', $json['template']);
    }
}
