<?php

namespace Mxent\Pwax\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The document metadata for one page.
 *
 * Kept as a value object rather than assembled in the view, because it has to be produced
 * twice and be identical both times: once as tags in the document a full page load gets,
 * and once inside the JSON payload the runtime gets, so a client-side navigation can
 * update what the browser would otherwise have replaced for it. A page whose title moves
 * with the route and whose description does not is worse than one that sets neither.
 *
 * @implements Arrayable<string, mixed>
 */
final class Head implements Arrayable, JsonSerializable
{
    /**
     * @param  list<array{attribute: string, key: string, content: string}>  $meta
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $canonical = null,
        public readonly array $meta = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->description === null
            && $this->canonical === null
            && $this->meta === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'description' => $this->description,
            'canonical' => $this->canonical,
            'meta' => $this->meta,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
