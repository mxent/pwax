<?php

namespace Mxent\Pwax\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every response Pwax serves carries.
 *
 * One place, applied to all of them: the page a visitor loads, the JSON payload a
 * navigation fetches, the runtime bundle, the manifests, the offline shell.
 *
 * That is the whole point of the class. Decide them inside `PwaxController` instead and
 * the offline shell is hardened while the application's own pages — the ones every visitor
 * actually loads — are not. Worse, the two then disagree: the same document is cross-origin
 * isolated when the service worker answers a navigation from the shell and not when the
 * server answers it, so a cross-origin font loads online and is refused offline.
 *
 * Two shapes, because a document and a script want different things. A document can be
 * framed, can be opened by another origin, and can ask for a camera; a piece of
 * JavaScript is inert when framed and asks for nothing. `nosniff` and `Referrer-Policy`
 * are on both.
 *
 * Every value is overridable through `pwax.security.*`, and `null` or `''` drops the
 * header entirely.
 */
final class SecurityHeaders
{
    /**
     * Deny the features that reach hardware, sensors or another origin's data; allow the
     * document its own use of the ones a progressive web app is actually built on.
     *
     * The distinction matters more than it looks. A blanket deny — which is what this
     * defaulted to — switched off `web-share`, `screen-wake-lock`, `fullscreen`,
     * `clipboard-write` and `publickey-credentials-get`, four of which this package
     * exposes an API for and the fifth of which is how a Laravel application signs
     * someone in with a passkey. `window.pwax.share()` rejected on any navigation the
     * worker answered from the offline shell, and nothing said why.
     *
     * Only features browsers actually implement are listed. An unrecognised name is not
     * an error, but every one of them is a console warning on every page load, and a
     * header nobody can read past is a header nobody edits.
     */
    private const PERMISSIONS_POLICY = 'accelerometer=(), ambient-light-sensor=(), battery=(), '
        . 'bluetooth=(), camera=(), display-capture=(), document-domain=(), encrypted-media=(), '
        . 'gamepad=(), geolocation=(), gyroscope=(), hid=(), idle-detection=(), local-fonts=(), '
        . 'magnetometer=(), microphone=(), midi=(), payment=(), serial=(), sync-xhr=(), usb=(), '
        . 'window-management=(), xr-spatial-tracking=(), '
        . 'autoplay=(self), clipboard-read=(self), clipboard-write=(self), fullscreen=(self), '
        . 'picture-in-picture=(self), publickey-credentials-create=(self), '
        . 'publickey-credentials-get=(self), screen-wake-lock=(self), storage-access=(self), '
        . 'web-share=(self)';

    public function __construct(private readonly Config $config) {}

    /**
     * Harden a response.
     *
     * Generic in the response type, so a caller that declares it returns a `JsonResponse`
     * can pass one through here and still be returning a `JsonResponse`.
     *
     * @template TResponse of Response
     *
     * @param  TResponse  $response
     * @param  bool  $document  True for HTML a browser renders as a page, false for an
     *                          asset — a script, a JSON payload, a manifest.
     * @return TResponse
     */
    public function apply(Response $response, bool $document): Response
    {
        // Every response here already declares an accurate `Content-Type`, so this is
        // defence in depth rather than a fix — but these endpoints serve JavaScript that
        // browsers execute and JSON that they do not, which is exactly where sniffing is
        // worth taking off the table.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $this->set($response, 'Referrer-Policy', 'pwax.security.referrer_policy', 'no-referrer');

        if (! $document) {
            return $response;
        }

        $this->set($response, 'X-Frame-Options', 'pwax.security.frame_options', 'SAMEORIGIN');

        // Left alone when something upstream has already set one — an application with its
        // own policy middleware has made a decision, and two of these headers on one
        // response is the intersection of both, which is nobody's intent.
        if (! $response->headers->has('Permissions-Policy')) {
            $this->set($response, 'Permissions-Policy', 'pwax.security.permissions_policy', self::PERMISSIONS_POLICY);
        }

        /*
         * `same-origin-allow-popups`, not `same-origin`.
         *
         * Both sever the `window.opener` reference a cross-origin page would otherwise
         * hold. `same-origin` also severs it for popups *this* document opens, which is
         * how an OAuth flow gets its result back — a Socialite popup login, a payment
         * provider's window, a "connect your account" button. `same-origin` as the
         * default breaks every one of them, silently.
         *
         * `same-origin` is still the right value for an application that wants
         * cross-origin isolation, and setting it here is how you ask for it.
         */
        $this->set(
            $response,
            'Cross-Origin-Opener-Policy',
            'pwax.security.cross_origin_opener_policy',
            'same-origin-allow-popups'
        );

        /*
         * Off unless asked for.
         *
         * `require-corp` refuses every cross-origin subresource that does not carry
         * `Cross-Origin-Resource-Policy` — an avatar from an S3 bucket, a font from a CDN,
         * an embedded video. It buys `SharedArrayBuffer` and high-resolution timers, which
         * a Blade-authored PWA almost never wants, and it costs the images. Defaulting it
         * on made the offline shell refuse assets the same page loaded online.
         */
        $this->set($response, 'Cross-Origin-Embedder-Policy', 'pwax.security.cross_origin_embedder_policy', null);

        return $response;
    }

    /**
     * Set a header from config, honouring `null` and `''` as "do not send this".
     */
    private function set(Response $response, string $header, string $key, ?string $default): void
    {
        // `Repository::get()` returns the default when the stored value is null (and the
        // documentation says so). `has()` is what distinguishes "the application set this
        // to null to suppress the header" from "the application has not said anything".
        $value = $this->config->has($key) ? $this->config->get($key) : $default;

        if ($value === null || $value === false) {
            return;
        }

        $value = (string) $value;

        if ($value !== '') {
            $response->headers->set($header, $value);
        }
    }
}
