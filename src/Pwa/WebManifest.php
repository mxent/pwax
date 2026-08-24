<?php

namespace Mxent\Pwax\Pwa;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Builds the Web App Manifest from configuration.
 *
 * Every configured member is emitted as-is, so a member the specification gains needs no
 * code change here. Two things are filled in when they are left null, because getting
 * either wrong is silent and expensive:
 *
 *   - `id`, which is the installed application's identity. A browser that has no `id`
 *     falls back to `start_url`, so changing `start_url` later orphans every existing
 *     install and silently creates a second app beside it.
 *   - `lang`, which assistive technology uses to pick a voice, and which almost nobody
 *     remembers to set. The application locale is a better default than nothing.
 */
class WebManifest
{
    public function __construct(
        private readonly Config $config,
        private readonly Application $app,
    ) {}

    /**
     * The manifest, cleaned and ready to encode.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var array<string, mixed> $manifest */
        $manifest = $this->config->get('pwax.manifest', []);

        $manifest['id'] ??= (string) ($manifest['start_url'] ?? '/');
        $manifest['lang'] ??= str_replace('_', '-', $this->app->getLocale());

        /** @var array<string, mixed> $clean */
        $clean = $this->clean($manifest);

        return $clean;
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    /**
     * A digest of the manifest body, used as its ETag and as a manifest-hash input.
     */
    public function hash(): string
    {
        return substr(hash('xxh128', $this->toJson()), 0, 16);
    }

    /**
     * The icon nearest to a requested pixel size, for `<link rel="apple-touch-icon">`.
     *
     * iOS ignores the manifest's icons for the home screen and reads that tag instead, so
     * an app with a perfectly good manifest still installs with a screenshot of the page
     * as its icon unless the tag is present.
     */
    public function appleTouchIcon(int $preferred = 180): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($this->icons() as $icon) {
            $src = $icon['src'] ?? null;

            if (! is_string($src) || $src === '') {
                continue;
            }

            // A maskable icon is drawn expecting the platform to crop it to a circle or
            // squircle; iOS does not, so using one here clips the artwork.
            $purpose = strtolower((string) ($icon['purpose'] ?? 'any'));

            if ($purpose !== '' && ! str_contains($purpose, 'any')) {
                continue;
            }

            $size = $this->largestSquare((string) ($icon['sizes'] ?? ''));

            if ($size === null) {
                continue;
            }

            $distance = abs($size - $preferred);

            if ($distance < $bestDistance) {
                $best = $src;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * The icon for `<link rel="icon">` — the browser tab, bookmarks, history.
     *
     * A manifest icon is used in preference to `favicon.ico` when one is declared, since
     * it is the artwork the application actually chose; the smallest square at or above
     * 32px, because a tab favicon is drawn at 16 or 32 and downscaling a 512px PNG for it
     * is wasted bytes on every page load.
     *
     * `null` rather than a guess when there is nothing: emitting a link to a file that is
     * not there costs a 404 on every navigation.
     */
    public function favicon(): ?string
    {
        $configured = $this->config->get('pwax.head.icon');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $best = null;
        $bestSize = PHP_INT_MAX;

        foreach ($this->icons() as $icon) {
            $src = $icon['src'] ?? null;

            if (! is_string($src) || $src === '') {
                continue;
            }

            $purpose = strtolower((string) ($icon['purpose'] ?? 'any'));

            if ($purpose !== '' && ! str_contains($purpose, 'any')) {
                continue;
            }

            $size = $this->largestSquare((string) ($icon['sizes'] ?? ''));

            if ($size === null || $size < 32 || $size >= $bestSize) {
                continue;
            }

            $best = $src;
            $bestSize = $size;
        }

        return $best ?? ($this->hasFaviconFile() ? '/favicon.ico' : null);
    }

    private function hasFaviconFile(): bool
    {
        $root = rtrim((string) $this->app->publicPath(), '/\\');

        return $root !== '' && is_file($root . DIRECTORY_SEPARATOR . 'favicon.ico');
    }

    /**
     * Icon entries, normalised to arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function icons(): array
    {
        /** @var array<int, mixed> $icons */
        $icons = $this->config->get('pwax.manifest.icons', []);

        return array_values(array_filter($icons, 'is_array'));
    }

    /**
     * The square sizes declared across all icons, e.g. `[192, 512]`.
     *
     * @return list<int>
     */
    public function declaredSizes(): array
    {
        $sizes = [];

        foreach ($this->icons() as $icon) {
            foreach (preg_split('/\s+/', trim((string) ($icon['sizes'] ?? ''))) ?: [] as $token) {
                if (preg_match('/^(\d+)x(\d+)$/i', $token, $m) === 1 && $m[1] === $m[2]) {
                    $sizes[] = (int) $m[1];
                }
            }
        }

        return array_values(array_unique($sizes));
    }

    /**
     * Does any icon declare the `maskable` purpose?
     *
     * Without one, Android draws the icon inside a white rounded square rather than
     * filling the launcher shape, which is the commonest reason an installed app looks
     * unfinished next to its neighbours.
     */
    public function hasMaskableIcon(): bool
    {
        foreach ($this->icons() as $icon) {
            if (str_contains(strtolower((string) ($icon['purpose'] ?? '')), 'maskable')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The largest square dimension in a `sizes` attribute, or null if it declares none.
     */
    private function largestSquare(string $sizes): ?int
    {
        $largest = null;

        foreach (preg_split('/\s+/', trim($sizes)) ?: [] as $token) {
            if (preg_match('/^(\d+)x(\d+)$/i', $token, $m) === 1 && $m[1] === $m[2]) {
                $largest = max($largest ?? 0, (int) $m[1]);
            }
        }

        return $largest;
    }

    /**
     * Drop empty members, recursively.
     *
     * `array_filter` with no callback would also drop `false` and `0`, which would
     * silently delete `prefer_related_applications => false` — a member whose entire
     * purpose is to say "no".
     */
    private function clean(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];

        foreach ($value as $key => $item) {
            $item = $this->clean($item);

            if ($item === null || $item === '' || $item === []) {
                continue;
            }

            $result[$key] = $item;
        }

        // A cleaned list must stay a list, or `icons` encodes as a JSON object.
        return array_is_list($value) ? array_values($result) : $result;
    }
}
