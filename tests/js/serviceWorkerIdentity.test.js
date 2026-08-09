/**
 * One set of caches, kept to one visitor at a time.
 *
 * The identity used to be part of every cache *name*, which made a cross-user read
 * impossible by construction — and cost a fresh set of caches per person, an empty one
 * minted on every sign-in, and everything re-fetched under the new name each time.
 *
 * The names are now fixed. The property is kept instead by emptying the visitor caches the
 * moment the worker learns it is serving somebody else, which it learns twice over: from
 * the identity a response declares, and from the one a request claims. The first is the
 * authority — the request that signs somebody in still carries who they were — and the
 * second is what covers a visitor who is offline from their very first request, where no
 * response ever arrives to announce the change.
 *
 * These tests are about the seam between those two.
 */
import { describe, expect, it } from 'vitest';
import { FakeCaches, ORIGIN, PAGE_HEADERS, createWorker } from './helpers/serviceWorkerHarness.js';

const SHELL = '/__pwax__/shell';
const RUNTIME = '/__pwax__/pwax.js';

/** Whatever `Shell::identity()` mints — opaque to the worker, which only compares it. */
const ALICE = 'a1b2c3d4e5f60718';
const BOB = '0f1e2d3c4b5a6978';

const PRIVATE = '/reports/summary';

function manifest(overrides = {}) {
    return {
        version: 'v1',
        cachePrefix: 'pwax',
        strategy: 'network-first',
        maxEntries: 60,
        navigationPreload: false,
        navigationStrategy: 'network-first',
        navigationUrls: [],
        shellUrl: SHELL,
        offlineUrl: null,
        assetPrefixes: ['/__pwax__/'],
        pageHeaders: PAGE_HEADERS,
        assetGroups: [{ name: 'app', installMode: 'prefetch', urls: [RUNTIME, SHELL] }],
        hashTable: { [RUNTIME]: 'rrr', [SHELL]: 'sss' },
        crossOrigin: [],
        critical: [RUNTIME, SHELL],
        hash: 'h1',
        ...overrides,
    };
}

/**
 * A server that answers `/reports/summary` with whoever asked for it.
 *
 * `down` cuts the network, which is the condition the fallback exists for and the one
 * under which a stale copy could reach the wrong person.
 */
function server(current, { down = new Set() } = {}) {
    return (path, request) => {
        if (down.has(path)) {
            return null;
        }

        if (path === '/sw.json') {
            return Response.json(current);
        }

        if (path === SHELL) {
            return new Response('<html>shell</html>', {
                headers: { 'Content-Type': 'text/html', 'Cache-Control': 'public' },
            });
        }

        if (path === RUNTIME) {
            return new Response('// runtime', {
                headers: { 'Content-Type': 'application/javascript', 'Cache-Control': 'public' },
            });
        }

        if (path === PRIVATE) {
            const who = (request && request.headers.get('X-Pwax-Identity')) || 'nobody';

            return new Response(`salary report for ${who}`, {
                headers: {
                    'Content-Type': 'text/plain',
                    'Cache-Control': 'private',
                    'X-Pwax-Identity': who,
                },
            });
        }

        return null;
    };
}

async function install(caches, current, options) {
    const worker = createWorker({ manifest: current, caches, routes: server(current, options) });

    await worker.dispatch('install');
    await worker.dispatch('activate');

    return worker;
}

/** The same URL, asked for by a particular visitor. `anon` is a claim, not an absence. */
const asIdentity = (identity) => ({ headers: { 'X-Pwax-Identity': identity } });

describe('one visitor at a time', () => {
    it('does not serve one identity a response cached by another', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        // Alice visits while online.
        const alice = await install(caches, current);
        expect(await (await alice.request(PRIVATE, asIdentity(ALICE))).text()).toBe(
            `salary report for ${ALICE}`
        );

        // Bob signs in on the same device and the network is gone, so no response ever
        // arrives to announce him. His *request* says who he is, and that is enough.
        const bob = createWorker({
            manifest: current,
            caches,
            routes: server(current, { down: new Set([PRIVATE]) }),
        });
        await bob.dispatch('activate');

        const response = await bob.request(PRIVATE, asIdentity(BOB));

        // A network error is the correct answer. Alice's report is not.
        expect(response.type).toBe('error');
    });

    it('does not serve a guest a response cached by a signed-in visitor', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        const alice = await install(caches, current);
        await alice.request(PRIVATE, asIdentity(ALICE));

        // `anon`, explicitly. The runtime sends the header for a signed-out visitor too,
        // precisely so this case is a claim the worker can act on rather than a silence.
        const guest = createWorker({
            manifest: current,
            caches,
            routes: server(current, { down: new Set([PRIVATE]) }),
        });
        await guest.dispatch('activate');

        expect((await guest.request(PRIVATE, asIdentity('anon'))).type).toBe('error');
    });

    it('still serves an identity its own cached response', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        const online = await install(caches, current);
        await online.request(PRIVATE, asIdentity(ALICE));

        const offline = createWorker({
            manifest: current,
            caches,
            routes: server(current, { down: new Set([PRIVATE]) }),
        });
        await offline.dispatch('activate');

        // The point of caching at all. Keeping one visitor at a time must not cost the
        // visitor their own copy.
        expect(await (await offline.request(PRIVATE, asIdentity(ALICE))).text()).toBe(
            `salary report for ${ALICE}`
        );
    });

    it('empties on the identity a response reports, not the one the request claims', async () => {
        const caches = new FakeCaches();
        const current = manifest({
            assetGroups: [
                { name: 'app', installMode: 'prefetch', urls: [RUNTIME, SHELL] },
                { name: 'pages', installMode: 'prefetch', kind: 'page', urls: [] },
            ],
        });

        // The request that carries someone into their account still holds the identity
        // they had before it: signing in is a client-side navigation, so nothing has
        // reloaded to refresh the header. The response is the only thing that knows.
        const worker = createWorker({
            manifest: current,
            caches,
            routes: (path) => {
                if (path === '/sw.json') {
                    return Response.json(current);
                }

                if (path === '/dashboard') {
                    return new Response(JSON.stringify({ template: '<p>hello</p>' }), {
                        headers: {
                            'Content-Type': 'application/json',
                            'Cache-Control': 'no-store, private',
                            'X-Pwax-Identity': ALICE,
                            Vary: 'X-Pwax-Component, X-Requested-With, Accept',
                        },
                    });
                }

                return null;
            },
        });

        await worker.dispatch('install');
        await worker.dispatch('activate');

        // Sent as a guest; answered as Alice.
        await worker.request('/dashboard', {
            headers: { ...PAGE_HEADERS, 'X-Pwax-Identity': 'anon' },
        });

        const held = async (name) => (await (await caches.open(name)).keys()).map((key) => key.url);

        // Stored, and stored once. The wipe that the change triggers runs before the write,
        // so the page that announced the new visitor is not thrown away with the last one's.
        expect(await held('pwax-pages-v1-h1')).toEqual([`${ORIGIN}/dashboard`]);
    });

    it('does not mint a cache just because someone read', async () => {
        const caches = new FakeCaches();
        const current = manifest({ strategy: 'network-only' });

        await install(caches, current);

        const before = await caches.keys();

        // Nothing has stored this and the network answers. A read must not bring a cache
        // into existence: `caches.open` creates, and an empty cache left behind by a
        // lookup makes the worker look as though it holds something when it holds nothing.
        const bob = createWorker({ manifest: current, caches, routes: server(current) });
        await bob.dispatch('activate');
        await bob.request(RUNTIME, asIdentity(BOB));

        expect(await caches.keys()).toEqual(before);
    });

    it('still serves the shared precache whoever is asking', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        await install(caches, current);

        // The framework, the shell and the components are the application itself — the
        // same bytes for everyone, and never emptied by a sign-in. Otherwise every
        // sign-in re-downloads the app.
        const bob = createWorker({
            manifest: current,
            caches,
            routes: server(current, { down: new Set([RUNTIME]) }),
        });
        await bob.dispatch('activate');

        expect(await (await bob.request(RUNTIME, asIdentity(BOB))).text()).toBe('// runtime');
    });
});

describe('the build’s own page copies', () => {
    it('survive an identity change', async () => {
        const caches = new FakeCaches();
        const current = manifest({
            assetGroups: [
                { name: 'app', installMode: 'prefetch', urls: [RUNTIME, SHELL] },
                { name: 'pages', installMode: 'prefetch', kind: 'page', urls: [] },
            ],
        });

        const worker = await install(caches, current);

        await worker.request(PRIVATE, asIdentity(ALICE));
        await worker.request(PRIVATE, asIdentity(BOB));

        // Not a person: this is what the build fetched without cookies, the copies every
        // visitor falls back to. Emptying it on a sign-in would take the application
        // offline for the person who just arrived.
        expect(await caches.keys()).toContain('pwax-pages-v1-h1-install');
    });

    it('are not dropped by forgetting the visitor', async () => {
        const caches = new FakeCaches();
        const worker = await install(caches, manifest());

        await worker.dispatch('message', {
            data: { type: 'PWAX_FORGET_IDENTITY' },
            ports: [{ postMessage: () => {} }],
        });

        expect(await caches.keys()).toContain('pwax-pages-v1-h1-install');
        expect(await caches.keys()).toContain('pwax-precache-v1-h1');
    });
});
