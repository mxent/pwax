<?php

namespace Mxent\Pwax\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

/**
 * The compiled result of a Blade view that contains Vue single-file-component blocks.
 *
 * `hash` is a content digest of the whole component. It backs the HTTP `ETag` on the
 * component endpoints and doubles as the client-side module cache key, so a component
 * whose source has not changed is never compiled twice in the browser.
 *
 * @implements Arrayable<string, mixed>
 */
final class Component implements Arrayable, Jsonable, JsonSerializable
{
    /**
     * @param  list<string>  $externalStyles  hrefs from `<link href="...">`
     * @param  list<string>  $externalScripts  srcs from `<script src="...">`
     */
    public function __construct(
        public readonly string $view,
        public readonly string $template = '',
        public readonly string $script = '',
        public readonly string $style = '',
        public readonly array $externalStyles = [],
        public readonly array $externalScripts = [],
        public readonly ?string $scopeId = null,
        public readonly ?string $id = null,
    ) {}

    /**
     * A stable digest of everything the browser receives.
     */
    public function hash(): string
    {
        return substr(hash('xxh128', implode("\0", [
            $this->template,
            $this->script,
            $this->style,
            implode(',', $this->externalStyles),
            implode(',', $this->externalScripts),
            (string) $this->scopeId,
        ])), 0, 16);
    }

    public function isEmpty(): bool
    {
        return $this->template === ''
            && $this->script === ''
            && $this->style === ''
            && $this->externalStyles === []
            && $this->externalScripts === [];
    }

    /**
     * Return a copy carrying the signed identifier the client uses to address it.
     */
    public function withId(string $id): self
    {
        return new self(
            view: $this->view,
            template: $this->template,
            script: $this->script,
            style: $this->style,
            externalStyles: $this->externalStyles,
            externalScripts: $this->externalScripts,
            scopeId: $this->scopeId,
            id: $id,
        );
    }

    /**
     * The wire format consumed by the client runtime.
     *
     * The `style` / `styles` / `template` / `script` / `scripts` keys are kept from
     * 1.x so hand-written client code keeps working; `id`, `hash` and `scope` are new.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hash' => $this->hash(),
            'scope' => $this->scopeId,
            'template' => $this->template,
            'script' => $this->script,
            'style' => $this->style,
            'styles' => $this->externalStyles,
            'scripts' => $this->externalScripts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }
}
