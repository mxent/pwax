/**
 * Cache partitioning between signed-in identities.
 *
 * The package makes an unusually strong claim about this — `identityOf()`'s own docblock
 * says a cross-user read is *impossible*, "because they were never reachable under this
 * name to begin with", and the README repeats it as the reason storing page payloads at
 * all is safe. That claim is about a pair of properties, and only one of them was tested:
 *
 *   - **Writes** go into a cache named for the identity that made the request. Covered
 *     since identity partitioning shipped.
 *   - **Reads** must be confined to the same name. They were not. `networkFirst()` and
 *     `staleWhileRevalidate()` both called the global `caches.match(request)`, which by
 *     specification walks *every* cache on the origin — so the offline fallback would
 *     happily answer one visitor with the entry another had left behind.
 *
 * Partitioning that only holds on the way in is not partitioning. These tests exercise
 * the way out.
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
 * `down` cuts the network, which is the only condition under which the fallback that
 * leaked could run.
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
                headers: { 'Content-Type': 'text/plain', 'Cache-Control': 'private' },
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

/** The same URL, asked for by a particular signed-in visitor. */
const asIdentity = (identity) => ({ headers: { 'X-Pwax-Identity': identity } });

describe('cache partitioning between identities', () => {
    it('does not serve one identity a response cached by another', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        // Alice visits while online. Her copy lands in a cache named for her.
        const alice = await install(caches, current);
        const warm = await alice.request(PRIVATE, asIdentity(ALICE));
        expect(await warm.text()).toBe(`salary report for ${ALICE}`);

        // Bob signs in on the same device and the network is gone.
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

        // No identity header at all — a signed-out visitor on a shared machine.
        const guest = createWorker({
            manifest: current,
            caches,
            routes: server(current, { down: new Set([PRIVATE]) }),
        });
        await guest.dispatch('activate');

        const response = await guest.request(PRIVATE);

        expect(response.type).toBe('error');
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

        const response = await offline.request(PRIVATE, asIdentity(ALICE));

        // The point of caching at all. Scoping reads must not cost the owner their copy.
        expect(await response.text()).toBe(`salary report for ${ALICE}`);
    });

    it('files a page by the identity the response reports, not the request', async () => {
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

        // Sent as a guest — no identity header at all.
        await worker.request('/dashboard', { headers: PAGE_HEADERS });

        // `install()` opens the anon pages cache whether or not it stores anything, so
        // the question is what is *in* each — not which names exist.
        const held = async (name) => (await (await caches.open(name)).keys()).map((key) => key.url);

        const names = await caches.keys();
        const pages = names.filter((name) => name.startsWith('pwax-pages-'));
        const mine = pages.find((name) => name.endsWith(`-${ALICE}`));
        const guest = pages.find((name) => name.endsWith('-anon'));

        expect(mine).toBeDefined();
        expect(await held(mine)).toEqual([`${ORIGIN}/dashboard`]);
        expect(guest ? await held(guest) : []).toEqual([]);
    });

    it('does not mint a cache just because someone read', async () => {
        const caches = new FakeCaches();
        const current = manifest({ strategy: 'network-only' });

        await install(caches, current);

        // Bob asks for something nothing has stored, and the network answers. Scoping the
        // read must not leave an empty cache named after him: that is litter, and on a
        // shared device it is also a list of who has used it.
        const bob = createWorker({
            manifest: current,
            caches,
            routes: server(current),
        });
        await bob.dispatch('activate');
        await bob.request(RUNTIME, asIdentity(BOB));

        const names = await caches.keys();

        expect(names.filter((name) => name.endsWith(`-${BOB}`))).toEqual([]);
    });

    it('still serves the shared precache to every identity', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        await install(caches, current);

        // The framework, the shell and the components are the application itself — the
        // same bytes for everyone, precached once. Scoping reads must not partition them,
        // or every visitor re-downloads the app.
        const bob = createWorker({
            manifest: current,
            caches,
            routes: server(current, { down: new Set([RUNTIME]) }),
        });
        await bob.dispatch('activate');

        const response = await bob.request(RUNTIME, asIdentity(BOB));

        expect(await response.text()).toBe('// runtime');
    });
});

describe('the build’s own page copies', () => {
    it('survive a sweep of the oldest identities', async () => {
        const caches = new FakeCaches();
        const current = manifest({
            identityCacheLimit: 1,
            assetGroups: [
                { name: 'app', installMode: 'prefetch', urls: [RUNTIME, SHELL] },
                { name: 'pages', installMode: 'prefetch', kind: 'page', urls: [] },
            ],
        });

        const worker = await install(caches, current);

        // Two people sign in, past the limit of one, so the sweep runs.
        for (const who of [ALICE, BOB]) {
            await caches.open(`pwax-pages-v1-h1-${who}`);
        }

        await worker.dispatch('activate');

        const names = await caches.keys();

        // Neither reserved label is a person. `install` is the build's own copies, which
        // every identity falls back to; evicting it to make room for one visitor would
        // take the application offline for everyone else.
        expect(names).toContain('pwax-pages-v1-h1-install');
    });

    it('are not dropped by forgetting an identity', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        const worker = await install(caches, current);
        await caches.open('pwax-pages-v1-h1-install');

        await worker.dispatch('message', {
            data: { type: 'PWAX_FORGET_IDENTITY', identity: 'install' },
            ports: [{ postMessage: () => {} }],
        });

        expect(await caches.keys()).toContain('pwax-pages-v1-h1-install');
    });
});
