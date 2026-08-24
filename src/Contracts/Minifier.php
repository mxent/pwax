<?php

namespace Mxent\Pwax\Contracts;

/**
 * Minifies the JavaScript and CSS blocks of a component before they go over the wire.
 *
 * Implementations must be lossless in behaviour and must never throw: a minifier that
 * cannot process a source is expected to return that source unchanged. Breaking a
 * working component to save a few bytes is never the right trade.
 */
interface Minifier
{
    public function js(string $source): string;

    public function css(string $source): string;
}
