<?php

namespace Mxent\Pwax\Minification;

use Mxent\Pwax\Contracts\Minifier;

/**
 * Passes sources through untouched.
 *
 * This is the default in non-production environments, where readable stack traces and
 * source positions matter far more than payload size. It is also a reasonable choice in
 * production when the web server already applies gzip or brotli, which recovers most of
 * the same bytes with no CPU cost per request and no risk of mangling valid JavaScript.
 */
class NullMinifier implements Minifier
{
    public function js(string $source): string
    {
        return $source;
    }

    public function css(string $source): string
    {
        return $source;
    }
}
