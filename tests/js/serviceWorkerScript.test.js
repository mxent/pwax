import { describe, expect, it } from 'vitest';
import { FakeCaches, createWorker } from './helpers/serviceWorkerHarness.js';

const SHELL = '/__pwax__/shell';
const RUNTIME = '/__pwax__/pwax.js';
const VUE = '/vendor/pwax/vue.global.prod.js?v=3.5.41';
const MODAL = '/__pwax__/c/modal.js';
const HOME = '/__pwax__/c/home.js';

function manifest({ hash = 'h1', modalHash = 'aaa', overrides = {} } = {}) {
    return {
        version: 'v1',
        cachePrefix: 'pwax',
        strategy: 'network-first',
        maxEntries: 60,
        navigationPreload: true,
        shellUrl: SHELL,
        offlineUrl: null,
        assetPrefixes: ['/__pwax__/', '/vendor/pwax/'],
        assetGroups: [
            { name: 'app', installMode: 'prefetch', urls: [VUE, RUNTIME, SHELL] },
            { name: 'components', installMode: 'prefetch', urls: [MODAL, HOME] },
        ],
        hashTable: { [VUE]: 'vvv', [RUNTIME]: 'rrr', [MODAL]: modalHash, [HOME]: 'bbb' },
        crossOrigin: [],
        critical: [RUNTIME, SHELL],
        hash,
        ...overrides,
    };
}

/** A server that answers the manifest and every asset, minus anything in `down`. */
function server(current, down = new Set()) {
    return (path) => {
        if (down.has(path)) {
            return null;
        }

        if (path === '/sw.json') {
            return Response.json(current);
        }

        if (path === SHELL) {
            return new Response('<html>shell</html>', { headers: { 'Cache-Control': 'public' } });
        }

        if (path === '/offline') {
            return new Response('<html>bespoke</html>');
        }

        if (path === '/settings') {
            return new Response('{"template":"x"}', {
                headers: {
                    'Content-Type': 'application/json',
                    'Cache-Control': 'no-store, private',
                },
            });
        }

        return new Response(`body:${path}`, {
            headers: { 'Cache-Control': 'private, max-age=3600' },
        });
    };
}

/** Install and activate a worker against a fresh or given cache storage. */
async function boot(current = manifest(), { caches = new FakeCaches(), down } = {}) {
    const worker = createWorker({ manifest: current, caches, routes: server(current, down) });

    await worker.dispatch('install');
    await worker.dispatch('activate');

    return worker;
}

/** A worker with the same caches but no network at all. */
function offline(current, caches) {
    return createWorker({ manifest: current, caches, routes: () => null });
}

describe('installing', () => {
    it('caches the whole application, not just the pages that were visited', async () => {
        const worker = await boot();
        const cache = await worker.caches.open('pwax-precache-v1-h1');

        for (const url of [VUE, RUNTIME, SHELL, MODAL, HOME]) {
            expect(await cache.match(url), `${url} was not precached`).toBeTruthy();
        }
    });

    it('survives one asset that is missing', async () => {
        // `cache.addAll` is atomic: a single 404 rejected the whole install, and the
        // surrounding catch then activated a worker with an empty cache.
        const worker = await boot(manifest(), { down: new Set([MODAL]) });
        const cache = await worker.caches.open('pwax-precache-v1-h1');

        expect(await cache.match(RUNTIME)).toBeTruthy();
        expect(await cache.match(MODAL)).toBeFalsy();
    });

    it('refuses to install without the shell', async () => {
        const caches = new FakeCaches();
        const worker = createWorker({
            manifest: manifest(),
            caches,
            routes: server(manifest(), new Set([SHELL])),
        });

        await worker.dispatch('install');

        // Keeping a worker that works beats replacing it with one that answers every
        // offline navigation with an error.
        expect(worker.failed()).toBe(true);
        expect(await caches.has('pwax-precache-v1-h1')).toBe(false);
    });

    it('refuses to install without a manifest', async () => {
        const worker = createWorker({
            manifest: manifest(),
            caches: new FakeCaches(),
            routes: server(manifest(), new Set(['/sw.json'])),
        });

        await worker.dispatch('install');

        expect(worker.failed()).toBe(true);
    });

    it('reports a refused response as skipped, not as a failure', async () => {
        // `/settings` is served `no-store`, which is the server saying "this belongs to
        // one visitor" — not a network problem. Reporting it as a failed asset reads like
        // a broken install and sends people hunting for a connection fault.
        const current = manifest();
        current.assetGroups.push({ name: 'routes', installMode: 'prefetch', urls: ['/settings'] });

        const worker = await boot(current);
        const messages = worker.log.filter(Array.isArray).map((entry) => String(entry[1]));

        expect(messages.some((m) => m.includes('could not be fetched'))).toBe(false);
        expect(messages.some((m) => m.includes('no-store'))).toBe(true);

        // …and it genuinely is not stored.
        const cache = await worker.caches.open('pwax-precache-v1-h1');
        expect(await cache.match('/settings')).toBeFalsy();
    });

    it('still reports a genuinely missing asset as a failure', async () => {
        const worker = await boot(manifest(), { down: new Set([MODAL]) });
        const messages = worker.log.filter(Array.isArray).map((entry) => String(entry[1]));

        expect(messages.some((m) => m.includes('could not be fetched'))).toBe(true);
    });

    it('does not activate itself', async () => {
        const worker = await boot();

        // Skipping the wait reloads every open tab on each deploy and makes the update
        // prompt unobservable.
        expect(worker.log).not.toContain('skipWaiting');
    });

    it('activates when the page asks it to', async () => {
        const worker = await boot();

        await worker.dispatch('message', { data: { type: 'PWAX_SKIP_WAITING' } });

        expect(worker.log).toContain('skipWaiting');
    });
});

describe('updating', () => {
    it('re-downloads only what changed', async () => {
        const caches = new FakeCaches();
        await boot(manifest(), { caches });

        const next = manifest({ hash: 'h2', modalHash: 'ccc' });
        const worker = createWorker({ manifest: next, caches, routes: server(next) });
        await worker.dispatch('install');

        const url = (path) => new URL(path, 'https://app.test').href;

        expect(worker.fetches, 'an unchanged vendor bundle was re-downloaded').not.toContain(
            url(VUE)
        );
        expect(worker.fetches, 'an unchanged component was re-downloaded').not.toContain(url(HOME));
        expect(worker.fetches, 'the changed component was not re-downloaded').toContain(url(MODAL));
    });

    it('drops the superseded cache once the new one is live', async () => {
        const caches = new FakeCaches();
        await boot(manifest(), { caches });

        const next = manifest({ hash: 'h2' });
        const worker = createWorker({ manifest: next, caches, routes: server(next) });
        await worker.dispatch('install');
        await worker.dispatch('activate');

        expect(await caches.has('pwax-precache-v1-h2')).toBe(true);
        expect(await caches.has('pwax-precache-v1-h1')).toBe(false);
    });

    it('keeps the new cache when the worker is restarted between install and activate', async () => {
        const caches = new FakeCaches();
        await boot(manifest(), { caches });

        const next = manifest({ hash: 'h2' });

        // A worker can be terminated after installing. The one that wakes up to activate
        // must not mistake the cache the other just built for a stale one.
        await createWorker({ manifest: next, caches, routes: server(next) }).dispatch('install');
        await createWorker({ manifest: next, caches, routes: server(next) }).dispatch('activate');

        const cache = await caches.open('pwax-precache-v1-h2');
        expect(await cache.match(RUNTIME), 'activate deleted the cache install built').toBeTruthy();
    });

    it('leaves other libraries’ caches alone', async () => {
        const caches = new FakeCaches();
        await caches.open('workbox-precache-v1');

        await boot(manifest(), { caches });

        expect(await caches.has('workbox-precache-v1')).toBe(true);
    });
});

describe('what it will and will not store', () => {
    it('does not store a page the server marked no-store', async () => {
        const worker = await boot();

        await worker.request('/settings', { headers: { 'X-Pwax-Component': 'true' } });

        // Cache Storage ignores HTTP cache directives, so a worker that stores whatever it
        // fetches writes signed-in users' pages to disk for the next user of the device.
        const runtime = await worker.caches.open('pwax-runtime-v1');
        expect(await runtime.match('/settings')).toBeFalsy();
    });

    it('does not cache navigations at all', async () => {
        const worker = await boot();

        await worker.navigate('/dashboard');

        for (const name of await worker.caches.keys()) {
            const cache = await worker.caches.open(name);
            expect(await cache.match('/dashboard'), `stored in ${name}`).toBeFalsy();
        }
    });

    it('ignores other origins, non-GET requests and range requests', async () => {
        const worker = await boot();

        expect(await worker.request('https://other.test/x.js')).toBeUndefined();
        expect(await worker.request('/settings', { method: 'POST' })).toBeUndefined();
        expect(
            await worker.request('/video.mp4', { headers: { range: 'bytes=0-1' } })
        ).toBeUndefined();
    });

    it('bounds the runtime cache', async () => {
        const worker = await boot(manifest({ overrides: { maxEntries: 3 } }));

        for (let i = 0; i < 8; i++) {
            await worker.request(`/img/${i}.png`);
        }

        const runtime = await worker.caches.open('pwax-runtime-v1');
        expect((await runtime.keys()).length).toBeLessThanOrEqual(3);
    });

    it('never trims the precache', async () => {
        const worker = await boot(manifest({ overrides: { maxEntries: 1 } }));

        for (let i = 0; i < 10; i++) {
            await worker.request(`/img/${i}.png`);
        }

        // Sharing one bounded cache meant ordinary browsing could evict the app shell and
        // silently take offline capability away.
        const cache = await worker.caches.open('pwax-precache-v1-h1');
        expect(await cache.match(SHELL), 'browsing evicted the app shell').toBeTruthy();
        expect(await cache.match(RUNTIME), 'browsing evicted the runtime').toBeTruthy();
    });

    it('clears only its own caches on request', async () => {
        const caches = new FakeCaches();
        await caches.open('other-app-cache');
        const worker = await boot(manifest(), { caches });

        let replied = false;
        await worker.dispatch('message', {
            data: { type: 'PWAX_CLEAR_CACHES' },
            ports: [{ postMessage: () => (replied = true) }],
        });

        expect(replied, 'the page was never told the caches were gone').toBe(true);
        expect(await caches.has('pwax-precache-v1-h1')).toBe(false);
        expect(await caches.has('other-app-cache')).toBe(true);
    });
});

describe('with no network', () => {
    it('serves precached assets without touching the network at all', async () => {
        const worker = await boot();
        const before = worker.fetches.length;

        const response = await worker.request(RUNTIME);

        expect(await response.text()).toBe(`body:${RUNTIME}`);
        expect(worker.fetches.length).toBe(before);
    });

    it('serves components', async () => {
        const caches = new FakeCaches();
        await boot(manifest(), { caches });

        const response = await offline(manifest(), caches).request(MODAL);

        expect(await response.text()).toBe(`body:${MODAL}`);
    });

    it('answers a navigation with the offline shell', async () => {
        const caches = new FakeCaches();
        await boot(manifest(), { caches });

        const response = await offline(manifest(), caches).navigate('/dashboard');

        expect(await response.text()).toBe('<html>shell</html>');
    });

    it('prefers a configured offline page over the shell', async () => {
        const current = manifest({ overrides: { offlineUrl: '/offline' } });
        current.assetGroups[0].urls.push('/offline');

        const caches = new FakeCaches();
        await boot(current, { caches });

        const response = await offline(current, caches).navigate('/anything');

        expect(await response.text()).toBe('<html>bespoke</html>');
    });

    it('answers with a real page even when nothing was ever cached', async () => {
        const response = await offline(manifest(), new FakeCaches()).navigate('/x');

        expect(response.status).toBe(503);
        await expect(response.text()).resolves.toMatch(/You are offline/);
    });

    it('fails a request without reporting an unhandled rejection', async () => {
        const caches = new FakeCaches();
        await boot(manifest(), { caches });

        // Rethrowing into `respondWith` fails the request *and* logs
        // `Uncaught (in promise) TypeError: Failed to fetch` against the worker. The page
        // still sees a rejected fetch at its own call site either way.
        const response = await offline(manifest(), caches).request('/settings');

        expect(response).toBeInstanceOf(Response);
        expect(response.type).toBe('error');
    });

    it('resolves an uncached asset to an error response, never to undefined', async () => {
        const caches = new FakeCaches();
        await boot(manifest(), { caches });

        // Under an asset prefix, so this takes the stale-while-revalidate path. With
        // nothing cached and no network it used to resolve `undefined`, which
        // `respondWith` rejects with a far more confusing message than being offline.
        const response = await offline(manifest(), caches).request('/__pwax__/c/never-seen.js');

        expect(response).toBeInstanceOf(Response);
        expect(response.type).toBe('error');
    });
});

describe('being a good citizen of the dev server', () => {
    it('precaches a few at a time rather than all at once', async () => {
        // `php artisan serve` handles one request at a time. An app with a hundred
        // components would queue an install behind itself until connections were refused.
        const urls = Array.from({ length: 30 }, (_, i) => `/__pwax__/c/component-${i}.js`);
        const current = manifest();
        current.assetGroups.push({ name: 'many', installMode: 'prefetch', urls });

        let inFlight = 0;
        let peak = 0;

        const base = server(current);
        const routes = async (path) => {
            inFlight++;
            peak = Math.max(peak, inFlight);
            await new Promise((resolve) => setTimeout(resolve, 1));
            inFlight--;

            return base(path);
        };

        const worker = createWorker({ manifest: current, caches: new FakeCaches(), routes });
        await worker.dispatch('install');

        expect(peak).toBeLessThanOrEqual(6);
        // …and still fetched everything.
        expect(worker.fetches.length).toBeGreaterThanOrEqual(urls.length);
    });
});
