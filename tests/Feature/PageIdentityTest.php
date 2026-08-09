<?php

namespace Mxent\Pwax\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Mxent\Pwax\Tests\TestCase;

/**
 * Every page payload says who it was rendered for.
 *
 * The service worker names its caches after this value, and until it travelled on the
 * response it could only be read from the request — which is stale for exactly the request
 * that matters. Signing in through the runtime is a client-side navigation by design, so
 * an authenticated visitor keeps sending the guest label until something reloads the
 * document, and the first pages they open land where every guest on the device can read
 * them.
 *
 * Two carriers, deliberately. The header is for the worker, which sees responses and not
 * payloads. The payload field is for the runtime, which corrects `config.identity` from it
 * so the *next* request is right without costing a round trip to find out.
 */
class PageIdentityTest extends TestCase
{
    public function test_a_guest_payload_reports_no_identity(): void
    {
        $response = $this->get('/identity-page', $this->componentHeaders());

        $response->assertJsonPath('identity', null);
        $this->assertSame('anon', $response->headers->get('X-Pwax-Identity'));
    }

    public function test_a_signed_in_payload_reports_an_identity(): void
    {
        $response = $this->actingAs($this->user())->get('/identity-page', $this->componentHeaders());

        $identity = $response->json('identity');

        $this->assertIsString($identity);
        $this->assertNotSame('', $identity);
        $this->assertSame($identity, $response->headers->get('X-Pwax-Identity'));
    }

    /**
     * The HTML representation says it too, and the worker depends on it saying so.
     *
     * A navigation is the one request whose sender the worker cannot identify: the
     * runtime's fetches carry the header, but a document request made by the browser
     * carries cookies, which a worker cannot read. So it keeps a navigation's HTML only
     * when the response declares `anon` — a page belonging to nobody, and therefore safe to
     * hand to anybody. Without this header nothing is stored and every reload of a route
     * the build never precached falls back to the shell.
     */
    public function test_a_guest_document_reports_no_identity(): void
    {
        $response = $this->get('/identity-page');

        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertSame('anon', $response->headers->get('X-Pwax-Identity'));
    }

    public function test_a_signed_in_document_reports_the_identity(): void
    {
        $user = $this->user();

        // The same value the payload carries, because the worker uses it to tell one from
        // the other: a document rendered for somebody is never stored.
        $this->assertSame(
            $this->actingAs($user)->get('/identity-page', $this->componentHeaders())->json('identity'),
            $this->actingAs($user)->get('/identity-page')->headers->get('X-Pwax-Identity'),
        );
    }

    public function test_the_identity_is_opaque(): void
    {
        // Long and distinctive on purpose. A single-digit id is a substring of most
        // sixteen-character hex strings by luck alone, so asserting against one tests the
        // random number generator rather than the code.
        $user = $this->user(90210111213);

        $identity = (string) $this->actingAs($user)
            ->get('/identity-page', $this->componentHeaders())
            ->json('identity');

        // Never the user's own identifier. It would be published in a JSON island on
        // every page, and it is the same id every other system knows this person by.
        $this->assertStringNotContainsString((string) $user->getAuthIdentifier(), $identity);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $identity);
    }

    public function test_two_users_get_different_identities(): void
    {
        $first = (string) $this->actingAs($this->user(1))
            ->get('/identity-page', $this->componentHeaders())
            ->json('identity');

        $second = (string) $this->actingAs($this->user(2))
            ->get('/identity-page', $this->componentHeaders())
            ->json('identity');

        // Sharing one would merge two people's caches, which is the whole failure this
        // machinery exists to prevent.
        $this->assertNotSame($first, $second);
    }

    public function test_the_identity_is_stable_for_one_user(): void
    {
        $user = $this->user();

        // Unstable would be as bad as shared, differently: a new cache per request, and
        // an application that is never actually available offline.
        $this->assertSame(
            $this->actingAs($user)->get('/identity-page', $this->componentHeaders())->json('identity'),
            $this->actingAs($user)->get('/identity-page', $this->componentHeaders())->json('identity'),
        );
    }

    /**
     * A user with an identifier and nothing else — no model, no table, no database.
     */
    private function user(int $id = 1): Authenticatable
    {
        return new GenericUser(['id' => $id]);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/identity-page', fn () => pwaxRender('pages.home'));
    }
}
