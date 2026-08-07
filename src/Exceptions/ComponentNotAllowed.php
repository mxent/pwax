<?php

namespace Mxent\Pwax\Exceptions;

use RuntimeException;

/**
 * Thrown when a view name is well-formed and correctly signed, but falls outside the
 * namespaces an application has opted to expose via `pwax.components.allowed`.
 *
 * This is defence in depth: signing already prevents a client from naming a view the
 * server never emitted, and this narrows the blast radius further if a signed
 * identifier ever leaks.
 */
class ComponentNotAllowed extends RuntimeException
{
    public static function for(string $view): self
    {
        return new self(sprintf(
            'The view [%s] is not within any namespace listed in pwax.components.allowed.',
            $view
        ));
    }
}
