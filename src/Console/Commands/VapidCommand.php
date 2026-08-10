<?php

namespace Mxent\Pwax\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/**
 * Generate the VAPID key pair Web Push needs.
 *
 * The first step of setting up push, and the one with the least to read about it. Every
 * guide says "generate a VAPID key pair" and then assumes a Node tool, so an application
 * that has no Node — which is the entire premise of this package — is stuck at step one.
 * This needs nothing but `ext-openssl`, which Laravel already requires.
 *
 * What the keys are: an ECDSA P-256 pair the push service uses to know that two messages
 * to the same subscription came from the same sender. The public key also goes to the
 * browser at subscribe time, which is why it is safe to put in a page and the private one
 * is not.
 *
 * Rotating them invalidates every existing subscription. Generate once, put both in `.env`,
 * and keep them for the life of the application.
 */
class VapidCommand extends Command
{
    protected $signature = 'pwax:vapid {--json : Output the pair as JSON}';

    protected $description = 'Generate a VAPID key pair for Web Push';

    public function handle(): int
    {
        if (! function_exists('openssl_pkey_new')) {
            $this->components->error('ext-openssl is not available, so a key pair cannot be generated.');

            return self::FAILURE;
        }

        try {
            $pair = $this->generate();
        } catch (Throwable $e) {
            $this->components->error('Could not generate a key pair: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($pair, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('VAPID key pair generated. Add both to your .env:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY=' . $pair['public']);
        $this->line('VAPID_PRIVATE_KEY=' . $pair['private']);
        $this->newLine();

        $this->components->bulletList([
            'The public key is sent to the browser and belongs in config; the private key never leaves the server.',
            'Rotating them invalidates every existing subscription, so generate once and keep them.',
            'Set pwax.push.public_key and pwax.push.endpoint, then call window.pwax.push.subscribe().',
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array{public: string, private: string}
     */
    private function generate(): array
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new \RuntimeException((string) openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new \RuntimeException('OpenSSL did not return the curve parameters.');
        }

        // Left-padded to 32 bytes each. OpenSSL returns the raw integers, so a coordinate
        // that happens to start with a zero byte comes back short — and a 64-byte public
        // key that is sometimes 63 is a subscription that sometimes fails to verify, which
        // is about as unpleasant a bug as this area has.
        $pad = static fn (string $value): string => str_pad($value, 32, "\0", STR_PAD_LEFT);

        return [
            // `0x04` marks an uncompressed point, which is the form the Push API expects.
            'public' => self::base64url("\x04" . $pad($details['ec']['x']) . $pad($details['ec']['y'])),
            'private' => self::base64url($pad($details['ec']['d'])),
        ];
    }

    /**
     * base64url, unpadded — what the Push API and JWT both use.
     */
    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
