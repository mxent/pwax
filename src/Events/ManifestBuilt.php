<?php

namespace Mxent\Pwax\Events;

/**
 * The service worker's asset manifest was rebuilt.
 *
 * Fired on a real build, not on a read of the memo, so it fires roughly once per deploy
 * rather than once per request. Useful for pushing the manifest hash into a deploy log, or
 * for failing a deployment when `warnings` is not empty — the same list
 * `php artisan pwax:precache` prints.
 */
class ManifestBuilt
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(public readonly array $manifest) {}

    public function hash(): string
    {
        return (string) ($this->manifest['hash'] ?? '');
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        /** @var list<string> $warnings */
        $warnings = array_values((array) ($this->manifest['warnings'] ?? []));

        return $warnings;
    }
}
