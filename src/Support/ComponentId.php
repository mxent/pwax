<?php

namespace Mxent\Pwax\Support;

use Mxent\Pwax\Exceptions\InvalidComponentId;

/**
 * Encodes and decodes the opaque identifiers used in component URLs.
 *
 * An identifier is `base64url(view name)` followed by a fixed-length HMAC of that
 * view name, keyed on the application key:
 *
 *     components.modal  ->  Y29tcG9uZW50cy5tb2RhbA + 3f9a1c0d4b8e2a67
 *
 * The signature is what makes the component endpoints safe to expose. Without it,
 * `/__pwax__/{name}.json` renders whatever view the caller names — which lets an
 * unauthenticated client dump any Blade template in the application. With it, only
 * identifiers this application itself emitted will resolve.
 *
 * Encoding is deterministic, so identifiers remain stable across requests and
 * deploys and stay usable as HTTP cache keys.
 */
class ComponentId
{
    /**
     * Length of the hex-encoded signature suffix. 16 hex characters is 64 bits, which
     * is far beyond what an online forgery attempt can search.
     */
    public const SIGNATURE_LENGTH = 16;

    /**
     * Domain separator, so a Pwax signature can never be replayed against another
     * feature that derives its own HMACs from the same application key.
     */
    private const PURPOSE = 'pwax:component:v2';

    public function __construct(private readonly string $key)
    {
        if ($this->key === '') {
            throw new \RuntimeException(
                'Pwax requires a non-empty application key to sign component identifiers. '
                . 'Run `php artisan key:generate`.'
            );
        }
    }

    /**
     * Turn a Blade view name into a signed, URL-safe identifier.
     *
     * @throws InvalidComponentId when the view name is not a legal Blade view name
     */
    public function encode(string $view): string
    {
        $view = self::validate($view);

        return self::base64UrlEncode($view) . $this->signature($view);
    }

    /**
     * Recover the Blade view name from a signed identifier.
     *
     * @throws InvalidComponentId when the identifier is malformed or unsigned by us
     */
    public function decode(string $id): string
    {
        if (strlen($id) <= self::SIGNATURE_LENGTH) {
            throw InvalidComponentId::malformed($id);
        }

        $payload = substr($id, 0, -self::SIGNATURE_LENGTH);
        $signature = substr($id, -self::SIGNATURE_LENGTH);

        if (! preg_match('/^[A-Za-z0-9_-]+$/', $payload) || ! preg_match('/^[a-f0-9]+$/', $signature)) {
            throw InvalidComponentId::malformed($id);
        }

        $view = self::base64UrlDecode($payload);

        if ($view === null) {
            throw InvalidComponentId::malformed($id);
        }

        // Compare before validating, so a bad signature and an illegal view name are
        // indistinguishable from the outside and neither reveals which check failed.
        if (! hash_equals($this->signature($view), $signature)) {
            throw InvalidComponentId::badSignature($id);
        }

        return self::validate($view);
    }

    /**
     * Verify an identifier without throwing.
     */
    public function verify(string $id): bool
    {
        try {
            $this->decode($id);

            return true;
        } catch (InvalidComponentId) {
            return false;
        }
    }

    /**
     * Assert that a string is a legal Blade view name.
     *
     * Blade view names are dot-delimited, optionally prefixed with a `namespace::`.
     * Anything containing a slash, a null byte, or a `..` sequence is rejected before
     * it can reach the view finder.
     *
     * @throws InvalidComponentId
     */
    public static function validate(string $name): string
    {
        if ($name === '' || strlen($name) > 255) {
            throw InvalidComponentId::invalidViewName($name);
        }

        if (! preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._:-]*[A-Za-z0-9])?$/', $name)) {
            throw InvalidComponentId::invalidViewName($name);
        }

        if (str_contains($name, '..')) {
            throw InvalidComponentId::pathTraversal($name);
        }

        // A namespace separator is `::`; a lone colon is not meaningful to Blade and is
        // more likely to be an injection attempt than a typo.
        if (str_contains(str_replace('::', '', $name), ':')) {
            throw InvalidComponentId::invalidViewName($name);
        }

        return $name;
    }

    private function signature(string $view): string
    {
        return substr(
            hash_hmac('sha256', self::PURPOSE . '|' . $view, $this->key),
            0,
            self::SIGNATURE_LENGTH
        );
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
