/**
 * What the worker does *after* it has answered.
 *
 * Storing a response is work for the next visit, not this one. Every page navigation used
 * to wait through a cache open, a put and a full `keys()` walk before the payload reached
 * the runtime; an API response waited through three storage round-trips. None of that
 * changes what the caller gets — only when they get it — so it belongs in `waitUntil`.
 *
 * These tests assert the ordering directly, by making the fake cache's `put` a promise the
 * test controls: if the response is still behind the write, awaiting it deadlocks.
 */
import { describe, expect, it } from 'vitest';
import { FakeCaches, PAGE_HEADERS, Request, createWorker } from './helpers/serviceWorkerHarness.js';

const SHELL = '/__pwax__/shell';
const RUNTIME = '/__pwax__/pwax.js';
const ABOUT = '/about';
const POSTS = '/api/posts';

function manifest(overrides = {}) {
    return {
        version: 'v1',
        cachePrefix: 'pwax',
        strategy: 'network-only',
        maxEntries: 60,
        maxEntryBytes: 0,
        navigationPreload: false,
        navigationStrategy: 'network-first',
        navigationUrls: [],
        shellUrl: SHELL,
        offlineUrl: null,
        assetPrefixes: [],
        pageHeaders: PAGE_HEADERS,
        assetGroups: [
            { name: 'app', installMode: 'prefetch', urls: [RUNTIME, SHELL] },
            {
                name: 'pages',
                installMode: 'prefetch',
                kind: 'page',
                strategy: 'freshness',
                urls: [],
                patterns: ['^\\/about$'],
            },
        ],
        dataGroups: [
            {
                name: 'posts',
                version: 1,
                patterns: ['^\\/api\\/posts$'],
                cacheConfig: { strategy: 'freshness', maxSize: 50, maxAge: 3600, timeout: 3000 },
            },
        ],
        hashTable: { [RUNTIME]: 'rrr', [SHELL]: 'sss' },
        crossOrigin: [],
        critical: [RUNTIME, SHELL],
        hash: 'h1',
        ...overrides,
    };
}

function server(current) {
    return (path) => {
        if (path === '/sw.json') {
            return Response.json(current);
        }

        if (path === ABOUT) {
            return new Response(JSON.stringify({ template: '<p>about</p>' }), {
                headers: {
                    'Content-Type': 'application/json',
                    Vary: 'X-Pwax-Component, X-Requested-With, Accept',
                    'Cache-Control': 'private, max-age=600',
                },
            });
        }

        if (path === POSTS) {
            return Response.json([{ id: 1 }], {
                headers: { 'Cache-Control': 'private, max-age=600' },
            });
        }

        return new Response(`body:${path}`, {
            headers: { 'Cache-Control': 'public, max-age=3600' },
        });
    };
}

/** Caches whose `put` hangs on a promise the test resolves. */
function blockingCaches() {
    const caches = new FakeCaches();
    let release;
    const gate = new Promise((resolve) => {
        release = resolve;
    });

    let armed = false;
    const open = caches.open.bind(caches);

    caches.open = async (name) => {
        const cache = await open(name);

        if (!cache.__gated) {
            cache.__gated = true;

            const put = cache.put.bind(cache);

            cache.put = async (request, response) => {
                if (armed) {
                    await gate;
                }

                return put(request, response);
            };
        }

        return cache;
    };

    return { caches, arm: () => (armed = true), release: () => release() };
}

async function boot(current, caches) {
    const worker = createWorker({ manifest: current, caches, routes: server(current) });

    await worker.dispatch('install');
    await worker.dispatch('activate');

    return worker;
}

/**
 * Dispatch a fetch without settling what it deferred, and hand back both halves.
 *
 * `worker.dispatch` awaits every `waitUntil` before returning, which is right for install
 * and exactly wrong here — the question is whether the response arrives without them.
 */
function fetchDetached(worker, request) {
    const waits = [];
    const responses = [];

    worker.emit('fetch', {
        request,
        preloadResponse: Promise.resolve(null),
        waitUntil: (promise) => waits.push(promise),
        respondWith: (promise) => responses.push(promise),
    });

    return { response: responses[0], waits };
}

describe('cache writes stay off the response path', () => {
    it('answers a page before the write to the pages cache finishes', async () => {
        const current = manifest();
        const { caches, arm, release } = blockingCaches();

        const worker = await boot(current, caches);

        arm();

        const { response, waits } = fetchDetached(
            worker,
            new Request(ABOUT, { headers: PAGE_HEADERS })
        );

        // The assertion. If the put were awaited before returning, this never settles.
        const settled = await response;

        await expect(settled.json()).resolves.toEqual({ template: '<p>about</p>' });

        expect(waits).toHaveLength(1);

        release();
        await Promise.all(waits);

        const cache = await caches.open('pwax-pages-h1');

        await expect(
            cache.match(new Request(ABOUT, { headers: PAGE_HEADERS }))
        ).resolves.toBeDefined();
    });

    it('answers an API request before the write to its data cache finishes', async () => {
        const current = manifest();
        const { caches, arm, release } = blockingCaches();

        const worker = await boot(current, caches);

        arm();

        const { response, waits } = fetchDetached(worker, new Request(POSTS));

        const settled = await response;

        await expect(settled.json()).resolves.toEqual([{ id: 1 }]);

        release();
        await Promise.all(waits);

        const cache = await caches.open('pwax-data-posts-v1');

        await expect(cache.match(new Request(POSTS))).resolves.toBeDefined();
    });
});

describe('data group versioning', () => {
    it('names the cache for the group version', async () => {
        const current = manifest();
        const caches = new FakeCaches();
        const worker = await boot(current, caches);

        await worker.request(POSTS);

        expect(await caches.keys()).toContain('pwax-data-posts-v1');
    });

    it('discards the old cache when the version is bumped', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, caches);
        await worker.request(POSTS);

        const before = await caches.open('pwax-data-posts-v1');
        await expect(before.match(new Request(POSTS))).resolves.toBeDefined();

        const bumped = manifest({ hash: 'h2' });
        bumped.dataGroups[0].version = 2;

        const next = createWorker({ manifest: bumped, caches, routes: server(bumped) });

        await next.dispatch('install');
        await next.dispatch('activate');

        // The whole point of the key: v1 is gone, and v2 starts empty.
        expect(await caches.keys()).not.toContain('pwax-data-posts-v1');
        await expect(
            caches.open('pwax-data-posts-v2').then((c) => c.match(new Request(POSTS)))
        ).resolves.toBeUndefined();
    });
});

describe('trimming', () => {
    it('does not walk the cache keys on every write', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, caches);

        const pages = await caches.open('pwax-pages-h1');
        let walks = 0;
        const keys = pages.keys.bind(pages);

        pages.keys = async () => {
            walks += 1;

            return keys();
        };

        for (let i = 0; i < 6; i += 1) {
            await worker.request(ABOUT);
        }

        // One seeding walk at most, not one per write. The cap is 60 and nothing here
        // comes close to it.
        expect(walks).toBeLessThanOrEqual(1);
    });
});
