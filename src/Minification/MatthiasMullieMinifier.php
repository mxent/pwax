<?php

namespace Mxent\Pwax\Minification;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use MatthiasMullie\Minify;
use Mxent\Pwax\Contracts\Minifier;
use Throwable;

/**
 * Minifies with `matthiasmullie/minify`.
 *
 * That library is regex-based rather than AST-based, so it can mis-handle syntax it was
 * not written for. Every call is therefore guarded twice:
 *
 *   1. Any throwable falls back to the original source with a warning.
 *   2. A result that is empty (or larger than the input) is treated as a failure, since
 *      both indicate the minifier lost its place rather than doing useful work.
 *
 * A component that renders slightly larger is always better than one that does not run.
 */
class MatthiasMullieMinifier implements Minifier
{
    public function js(string $source): string
    {
        return $this->guard($source, 'JavaScript', static fn (string $s): string => (new Minify\JS($s))->minify());
    }

    public function css(string $source): string
    {
        return $this->guard($source, 'CSS', static fn (string $s): string => (new Minify\CSS($s))->minify());
    }

    /**
     * @param  callable(string): string  $minify
     */
    private function guard(string $source, string $language, callable $minify): string
    {
        if (trim($source) === '') {
            return '';
        }

        try {
            $result = $minify($source);
        } catch (Throwable $e) {
            $this->report($language, $source, $e->getMessage());

            return $source;
        }

        if (trim($result) === '') {
            $this->report($language, $source, 'minifier returned an empty result');

            return $source;
        }

        if (strlen($result) > strlen($source)) {
            $this->report($language, $source, 'minifier grew the source; treating as a failure');

            return $source;
        }

        return $result;
    }

    private function report(string $language, string $source, string $reason): void
    {
        if (! function_exists('app') || ! app()->bound(ExceptionHandler::class)) {
            return;
        }

        Log::warning('pwax: ' . $language . ' minification failed, serving the original source.', [
            'reason' => $reason,
            'bytes' => strlen($source),
            'preview' => substr(trim($source), 0, 120),
        ]);
    }
}
