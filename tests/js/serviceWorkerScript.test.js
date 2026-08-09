import { describe, expect, it } from 'vitest';
import { FakeCaches, createWorker, navigation } from './helpers/serviceWorkerHarness.js';

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

    it('leaves caches it did not create alone', async () => {
        // The sweep is by prefix, and an origin may have caches belonging to something
        // else entirely — another worker, a library, the application's own code.
        // Deleting one of those is someone else's outage.
        const caches = new FakeCaches();
        await caches.open('site-assets-v1');

        await boot(manifest(), { caches });

        expect(await caches.has('site-assets-v1')).toBe(true);
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

        const body = await response.text();

        expect(response.status).toBe(503);
        expect(body).toMatch(/not available offline/);
        // A whole document, not a fragment: the shell's stylesheet belongs to a page that
        // never loaded, so this one carries its own.
        expect(body).toMatch(/<!DOCTYPE html>/);
        expect(body).toMatch(/role="alert"/);
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

describe('navigation preload', () => {
    /** A navigation the browser has already started fetching for us. */
    const withPreload = (worker, path, response) =>
        worker.dispatch('fetch', {
            request: navigation(path),
            preloadResponse: Promise.resolve(response),
        });

    it('uses the preloaded response instead of fetching again', async () => {
        const current = manifest({ overrides: { navigationUrls: [] } });
        const worker = await boot(current);
        const before = worker.fetches.length;

        const response = await withPreload(
            worker,
            '/dashboard',
            new Response('<html>preloaded</html>')
        );

        expect(await response.text()).toBe('<html>preloaded</html>');
        expect(worker.fetches.length).toBe(before);
    });

    it('uses it for a path the application does not own', async () => {
        // Navigation preload is enabled for the whole scope, so the browser has already
        // sent this request. Answering it with `fetch` would send it a second time and
        // discard the first — every declined navigation costing the server two.
        // Already compiled by the server: `navigationUrls` reaches the worker as regular
        // expressions, not as the globs written in config.
        const current = manifest({ overrides: { navigationUrls: ['^/app/.*$'] } });
        const worker = await boot(current);
        const before = worker.fetches.length;

        const response = await withPreload(
            worker,
            '/horizon/dashboard',
            new Response('<html>horizon</html>')
        );

        expect(await response.text()).toBe('<html>horizon</html>');
        expect(worker.fetches.length).toBe(before);
    });

    it('settles the preload it does not use', async () => {
        const current = manifest({
            overrides: { navigationStrategy: 'app-shell', navigationUrls: [] },
        });
        const worker = await boot(current);

        let settled = false;
        const preload = Promise.resolve(new Response('<html>ignored</html>')).then((r) => {
            settled = true;
            return r;
        });

        const response = await worker.dispatch('fetch', {
            request: navigation('/dashboard'),
            preloadResponse: preload,
        });

        // The shell answers, but the preload is still awaited. Left dangling it logs
        // "the navigation preload request was cancelled before preloadResponse settled"
        // in the console of every app on this strategy, which reads like a bug.
        expect(await response.text()).toBe('<html>shell</html>');
        expect(settled).toBe(true);
    });

    it('falls back to the network when the preload fails', async () => {
        const current = manifest({ overrides: { navigationUrls: [] } });
        const worker = await boot(current);

        const response = await worker.dispatch('fetch', {
            request: navigation('/dashboard'),
            preloadResponse: Promise.reject(new TypeError('Failed to fetch')),
        });

        // A preload can fail for reasons other than the network being gone, so a
        // rejection is not on its own an offline signal.
        expect(await response.text()).toBe('body:/dashboard');
    });
});

describe('a manifest with a bad pattern', () => {
    it('ignores the pattern rather than the whole navigation list', async () => {
        // An unusable pattern used to throw out of the match, which `route()` caught and
        // answered with the offline page — so one bad rule took every navigation in the
        // application down with it.
        const current = manifest({ overrides: { navigationUrls: ['^/app/.*$', '/broken/**'] } });
        const worker = await boot(current);

        const response = await worker.dispatch('fetch', {
            request: navigation('/app/dashboard'),
            preloadResponse: Promise.resolve(null),
        });

        expect(await response.text()).toBe('body:/app/dashboard');
        expect(worker.log).toContainEqual([
            'warn',
            'pwax sw: ignoring an asset pattern that will not compile',
            '/broken/**',
        ]);
    });
});

describe('the runtime strategy', () => {
    it('stores nothing for a URL the application never declared', async () => {
        // The default. A one-off download, a CSV export, a file under /storage — none of
        // it is part of the application, and all of it used to be kept.
        const current = manifest({ overrides: { strategy: 'network-only' } });
        const worker = await boot(current);

        const response = await worker.request('/exports/report.csv');

        expect(await response.text()).toBe('body:/exports/report.csv');

        const names = await worker.caches.keys();
        expect(names.filter((name) => name.startsWith('pwax-runtime-'))).toEqual([]);
    });

    it('keeps them when asked to', async () => {
        const current = manifest({ overrides: { strategy: 'network-first' } });
        const worker = await boot(current);

        await worker.request('/exports/report.csv');

        const runtime = await worker.caches.open('pwax-runtime-v1-anon');
        expect(await runtime.match('/exports/report.csv')).toBeTruthy();
    });

    it('refuses a response larger than the entry ceiling', async () => {
        const current = manifest({
            overrides: { strategy: 'network-first', maxEntryBytes: 1024 },
        });
        const caches = new FakeCaches();
        const base = server(current);

        const worker = createWorker({
            manifest: current,
            caches,
            routes: (path) =>
                path === '/big.bin'
                    ? new Response('x', {
                          headers: { 'Cache-Control': 'public', 'Content-Length': '99999' },
                      })
                    : base(path),
        });

        await worker.dispatch('install');
        await worker.dispatch('activate');

        // Handed back, just not kept: the entry cap counts entries, so one large response
        // can crowd out everything the cap was meant to protect.
        expect(await (await worker.request('/big.bin')).text()).toBe('x');

        const runtime = await caches.open('pwax-runtime-v1-anon');
        expect(await runtime.match('/big.bin')).toBeFalsy();
    });

    it('keeps a response with no declared length', async () => {
        // Measuring it would mean buffering the very responses the ceiling exists to
        // avoid buffering. The entry cap still bounds them.
        const current = manifest({
            overrides: { strategy: 'network-first', maxEntryBytes: 1024 },
        });
        const worker = await boot(current);

        await worker.request('/streamed');

        const runtime = await worker.caches.open('pwax-runtime-v1-anon');
        expect(await runtime.match('/streamed')).toBeTruthy();
    });
});
