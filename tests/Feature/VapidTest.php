<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mxent\Pwax\Tests\TestCase;

/**
 * `php artisan pwax:vapid`.
 *
 * Every Web Push guide starts "generate a VAPID key pair" and reaches for a Node tool,
 * which is the one thing an application built on this package is entitled not to have. The
 * keys are an ECDSA P-256 pair and `ext-openssl` makes them in four lines.
 *
 * The shapes are what matter here. A public key that is 64 bytes instead of 65, or a
 * coordinate that lost a leading zero, produces a subscription that fails to verify
 * sometimes — which is about the least debuggable failure this area has.
 */
class VapidTest extends TestCase
{
    /**
     * @return array{public: string, private: string}
     */
    private function generate(): array
    {
        // Not `$this->artisan()`: its assertions run against a mocked output buffer, and
        // what these tests need is the bytes the command actually printed.
        $this->withoutMockingConsoleOutput();

        $this->assertSame(0, Artisan::call('pwax:vapid', ['--json' => true]));

        /** @var array{public: string, private: string} $pair */
        $pair = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        return $pair;
    }

    private function decode(string $value): string
    {
        return (string) base64_decode(str_pad(strtr($value, '-_', '+/'), (int) (ceil(strlen($value) / 4) * 4), '=', STR_PAD_RIGHT), true);
    }

    public function test_the_public_key_is_an_uncompressed_p256_point(): void
    {
        $raw = $this->decode($this->generate()['public']);

        // 0x04 marks an uncompressed point, then 32 bytes of X and 32 of Y. This is the
        // form `applicationServerKey` is required to take.
        $this->assertSame(65, strlen($raw));
        $this->assertSame("\x04", $raw[0]);
    }

    public function test_the_private_key_is_a_32_byte_scalar(): void
    {
        $this->assertSame(32, strlen($this->decode($this->generate()['private'])));
    }

    /**
     * base64url, unpadded — what the Push API and JWT both use. A `+`, a `/` or a trailing
     * `=` here is rejected by the browser when the key is handed to `subscribe()`.
     */
    public function test_the_keys_are_unpadded_base64url(): void
    {
        foreach ($this->generate() as $key) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $key);
        }
    }

    public function test_each_run_produces_a_different_pair(): void
    {
        $this->assertNotSame($this->generate()['private'], $this->generate()['private']);
    }

    /**
     * The human-readable run has to name both variables, because copying them into `.env`
     * is the entire next step.
     */
    public function test_the_default_output_is_ready_to_paste_into_an_env_file(): void
    {
        $this->artisan('pwax:vapid')
            ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
            ->assertSuccessful();
    }

    /**
     * The guidance must name all three config keys a developer needs to set, so a
     * follow-the-instructions run does not miss one.
     *
     * `bulletList` renders through Termwind, which does not always emit through `doWrite`
     * in a way `expectsOutputToContain` can intercept. So the command also prints the
     * key names on their own lines, which the test can reliably match.
     */
    public function test_the_guidance_names_every_push_config_key(): void
    {
        $this->withoutMockingConsoleOutput();

        $exit = Artisan::call('pwax:vapid');

        $this->assertSame(0, $exit);

        $output = Artisan::output();

        $this->assertStringContainsString('pwax.push.public_key', $output);
        $this->assertStringContainsString('pwax.push.private_key', $output);
        $this->assertStringContainsString('pwax.push.endpoint', $output);
    }
}
