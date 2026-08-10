<?php

namespace Mxent\Pwax\Events;

use Mxent\Pwax\Data\Component;

/**
 * A Blade view was parsed into a component.
 *
 * Fired on a real compile only — never on a cache hit — so it is a reasonable place to
 * measure how often the compile cache is actually working, or to warm something of your
 * own from the same output. Listeners must not mutate the component: it is readonly, and
 * it may already have been written to the cache by the time this fires.
 */
class ComponentCompiled
{
    public function __construct(
        public readonly string $view,
        public readonly Component $component,
    ) {}
}
