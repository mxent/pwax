/**
 * Lazy asset groups, across deploys.
 *
 * A lazy group is the one that holds the big files — `/images/**`, `/fonts/**`,
 * `/media/**` are what ships in the default config. They are fetched on first use rather
 * than at install, and the whole point of declaring them is that they are then *kept*.
 *
 * They used to be kept in the precache, which is named for the build and deleted wholesale
 * on the next one. So every deploy silently discarded every image and font on the device
 * and re-downloaded each on next use — the exact churn the delta install exists to
 * prevent, applied to the largest files in the application. These tests are the contract
 * that says it does not happen: survive a deploy, drop only what actually changed, and let
 * `update_mode` decide whether a changed file is refetched now or on demand.
 */
import { describe, expect, it } from 'vitest';
import { FakeCaches, createWorker } from './helpers/serviceWorkerHarness.js';

const SHELL = '/__pwax__/shell';
const RUNTIME = '/__pwax__/pwax.js';
const LOGO = '/images/logo.svg';
const HERO = '/images/hero.png';

const LAZY_CACHE = 'pwax-lazy';

function manifest({ hash = 'h1', logoHash = 'logo-1', updateMode = 'prefetch' } = {}) {
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
        pageHeaders: {},
        assetGroups: [
            { name: 'app', installMode: 'prefetch', urls: [RUNTIME, SHELL] },
            {
                name: 'assets',
                installMode: 'lazy',
                updateMode,
                kind: 'asset',
                urls: [LOGO, HERO],
                patterns: ['^\\/images\\/.*$'],
            },
        ],
        hashTable: { [RUNTIME]: 'rrr', [SHELL]: 'sss', [LOGO]: logoHash, [HERO]: 'hero-1' },
        crossOrigin: [],
        critical: [RUNTIME, SHELL],
        hash,
    };
}

function server(current, bodies = {}) {
    return (path) => {
        if (path === '/sw.json') {
            return Response.json(current);
        }

        return new Response(bodies[path] || `body:${path}`, {
            headers: { 'Cache-Control': 'public, max-age=3600' },
        });
    };
}

async function boot(current, { caches = new FakeCaches(), bodies } = {}) {
    const worker = createWorker({ manifest: current, caches, routes: server(current, bodies) });

    await worker.dispatch('install');
    await worker.dispatch('activate');

    return worker;
}

/** What the lazy cache holds for a URL, as text. */
async function held(caches, path) {
    const cache = await caches.open(LAZY_CACHE);
    const hit = await cache.match(path, { ignoreVary: true });

    return hit ? hit.text() : undefined;
}

describe('lazy asset groups', () => {
    it('is not fetched at install', async () => {
        const caches = new FakeCaches();
        const worker = await boot(manifest(), { caches });

        expect(worker.sentRequest(LOGO)).toBeUndefined();
        await expect(held(caches, LOGO)).resolves.toBeUndefined();
    });

    it('is kept on first use, outside the build-keyed precache', async () => {
        const caches = new FakeCaches();

        await boot(manifest(), { caches });

        const worker = createWorker({
            manifest: manifest(),
            caches,
            routes: server(manifest()),
        });

        await worker.request(LOGO);

        await expect(held(caches, LOGO)).resolves.toBe(`body:${LOGO}`);

        // The distinction the bug turned on: not in the precache, which the next deploy
        // deletes by name.
        const precache = await caches.open('pwax-precache-h1');

        await expect(precache.match(LOGO, { ignoreVary: true })).resolves.toBeUndefined();
    });

    it('survives a deploy that did not change it', async () => {
        const caches = new FakeCaches();
        const first = manifest();

        await boot(first, { caches });

        const visitor = createWorker({ manifest: first, caches, routes: server(first) });
        await visitor.request(LOGO);

        // A new build. Everything about it differs except this one file.
        const second = manifest({ hash: 'h2' });
        const worker = createWorker({ manifest: second, caches, routes: server(second) });

        await worker.dispatch('install');
        await worker.dispatch('activate');

        await expect(held(caches, LOGO)).resolves.toBe(`body:${LOGO}`);

        // And it was not re-downloaded to get there.
        expect(worker.requests.filter((sent) => sent.url.endsWith(LOGO))).toHaveLength(0);
    });

    it('refetches a changed file at install when update_mode is prefetch', async () => {
        const caches = new FakeCaches();
        const first = manifest();

        await boot(first, { caches });

        const visitor = createWorker({ manifest: first, caches, routes: server(first) });
        await visitor.request(LOGO);

        const second = manifest({ hash: 'h2', logoHash: 'logo-2' });
        const worker = createWorker({
            manifest: second,
            caches,
            routes: server(second, { [LOGO]: 'redrawn' }),
        });

        await worker.dispatch('install');
        await worker.dispatch('activate');

        await expect(held(caches, LOGO)).resolves.toBe('redrawn');
    });

    it('drops a changed file instead, when update_mode is lazy', async () => {
        const caches = new FakeCaches();
        const first = manifest({ updateMode: 'lazy' });

        await boot(first, { caches });

        const visitor = createWorker({ manifest: first, caches, routes: server(first) });
        await visitor.request(LOGO);

        const second = manifest({ hash: 'h2', logoHash: 'logo-2', updateMode: 'lazy' });
        const worker = createWorker({
            manifest: second,
            caches,
            routes: server(second, { [LOGO]: 'redrawn' }),
        });

        await worker.dispatch('install');
        await worker.dispatch('activate');

        // Gone, not stale — and not fetched during the install either.
        await expect(held(caches, LOGO)).resolves.toBeUndefined();
        expect(worker.requests.filter((sent) => sent.url.endsWith(LOGO))).toHaveLength(0);

        // The next request for it fetches the new one.
        const after = createWorker({
            manifest: second,
            caches,
            routes: server(second, { [LOGO]: 'redrawn' }),
        });

        await after.request(LOGO);

        await expect(held(caches, LOGO)).resolves.toBe('redrawn');
    });

    it('drops a file the new build no longer ships', async () => {
        const caches = new FakeCaches();
        const first = manifest();

        await boot(first, { caches });

        const visitor = createWorker({ manifest: first, caches, routes: server(first) });
        await visitor.request(LOGO);

        const second = manifest({ hash: 'h2' });
        delete second.hashTable[LOGO];
        second.assetGroups[1].urls = [HERO];

        const worker = createWorker({ manifest: second, caches, routes: server(second) });

        await worker.dispatch('install');
        await worker.dispatch('activate');

        await expect(held(caches, LOGO)).resolves.toBeUndefined();
    });

    it('never fetches a lazy file the device did not already hold', async () => {
        const caches = new FakeCaches();

        await boot(manifest(), { caches });

        // Only the logo was ever requested; the hero must stay untouched across a deploy
        // that changed everything, because `install_mode: lazy` said not to fetch it.
        const visitor = createWorker({ manifest: manifest(), caches, routes: server(manifest()) });
        await visitor.request(LOGO);

        const second = manifest({ hash: 'h2', logoHash: 'logo-2' });
        const worker = createWorker({ manifest: second, caches, routes: server(second) });

        await worker.dispatch('install');
        await worker.dispatch('activate');

        expect(worker.requests.filter((sent) => sent.url.endsWith(HERO))).toHaveLength(0);
        await expect(held(caches, HERO)).resolves.toBeUndefined();
    });
});
