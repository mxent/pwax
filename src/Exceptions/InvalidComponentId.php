<?php

namespace Mxent\Pwax\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a component identifier is malformed, or when its signature does not
 * verify against the application key.
 *
 * A failing signature means the identifier was not produced by this application, so
 * the request is treated as an attempt to reach a view the server never exposed.
 */
class InvalidComponentId extends InvalidArgumentException
{
    public static function malformed(string $id): self
    {
        return new self('Malformed Pwax component identifier: ' . self::redact($id));
    }

    public static function badSignature(string $id): self
    {
        return new self('Pwax component identifier failed signature verification: ' . self::redact($id));
    }

    public static function invalidViewName(string $name): self
    {
        return new self('Invalid Pwax view name: ' . self::redact($name));
    }

    public static function pathTraversal(string $name): self
    {
        return new self('Pwax view name contains a path traversal sequence: ' . self::redact($name));
    }

    /**
     * Keep untrusted input out of logs at full length, but leave enough to debug with.
     */
    private static function redact(string $value): string
    {
        $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';

        return strlen($value) > 64 ? substr($value, 0, 64) . '…' : $value;
    }
}
