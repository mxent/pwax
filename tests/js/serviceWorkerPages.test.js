/**
 * Page payloads, offline.
 *
 * Caches are shared across visitors. Whatever the server returns for a URL is stored and
 * served to the next visitor that asks; there is no per-identity partition, no per-
 * identity wiping, and no per-identity cache name. This file is the offline contract:
 * the manifest tells the worker which routes are pages and exactly which headers to
 * ask for them with, and if those headers ever stop matching `Pwax::VARY` every page
 * lookup in every browser starts missing — silently, and with nothing in either
 * codebase to point at.
 */
import { describe, expect, it } from 'vitest';
import { FakeCaches, PAGE_HEADERS, Request, createWorker } from './helpers/serviceWorkerHarness.js';

const SHELL = '/__pwax__/shell';
const RUNTIME = '/__pwax__/pwax.js';

const ABOUT = '/about';
const DASHBOARD = '/dashboard';

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
        assetGroups: [
            { name: 'app', installMode: 'prefetch', urls: [RUNTIME, SHELL] },
            {
                name: 'pages',
                installMode: 'prefetch',
                kind: 'page',
                strategy: 'freshness',
                urls: [ABOUT],
            },
        ],
        hashTable: { [RUNTIME]: 'rrr', [SHELL]: 'sss' },
        crossOrigin: [],
        critical: [RUNTIME, SHELL],
        hash: 'h1',
        ...overrides,
    };
}

/**
 * A server that answers a page two ways, exactly as `ComponentResponse` does.
 *
 * With the component header it returns the JSON payload; without it, the HTML shell
 * marked `no-store`. Getting this right in the fake is the point — a server that always
 * returned JSON would let the broken worker pass.
 */
function server(current, { cacheable = [ABOUT], down = new Set() } = {}) {
    return (path, request) => {
        if (down.has(path)) {
            return null;
        }

        if (path === '/sw.json') {
            return Response.json(current);
        }

        if (path === SHELL) {
            return new Response(
                '<html><div id="pwax"></div><script id="pwax-initial">{"url":"' +
                    path +
                    '"}</script></html>',
                {
                    headers: { 'Content-Type': 'text/html', 'Cache-Control': 'public' },
                }
            );
        }

        if (path === ABOUT || path === DASHBOARD) {
            const wants = request && request.headers.get('X-Pwax-Component') === 'true';

            if (!wants) {
                // What ComponentResponse::shell() renders: the SPA shell with the
                // component inlined in a `pwax-initial` island. `no-store` because it
                // would carry a CSRF token for a session.
                return new Response(
                    `<html><div id="pwax"></div><script id="pwax-initial">{"url":"${path}"}</script></html>`,
                    {
                        headers: {
                            'Content-Type': 'text/html',
                            'Cache-Control': 'no-store, private',
                        },
                    }
                );
            }

            return new Response(JSON.stringify({ template: `<p>${path}</p>` }), {
                headers: {
                    'Content-Type': 'application/json',
                    Vary: 'X-Pwax-Component, X-Requested-With, Accept',
                    'Cache-Control': cacheable.includes(path)
                        ? 'private, max-age=600'
                        : 'no-store, private',
                },
            });
        }

        return new Response(`body:${path}`, {
            headers: { 'Cache-Control': 'private, max-age=3600' },
        });
    };
}

async function boot(current = manifest(), options = {}) {
    const caches = options.caches || new FakeCaches();
    const worker = createWorker({ manifest: current, caches, routes: server(current, options) });

    await worker.dispatch('install');
    await worker.dispatch('activate');

    return worker;
}

/** The same caches, with no network at all. */
function offline(current, caches) {
    return createWorker({ manifest: current, caches, routes: () => null });
}

/** The request the client runtime makes for a page. */
const asRuntime = (path) => new Request(path, { headers: PAGE_HEADERS });

/** Ask for a page the way the runtime does. */
const visit = (worker, path) =>
    worker.dispatch('fetch', {
        request: asRuntime(path),
        preloadResponse: Promise.resolve(null),
    });

describe('page payloads offline', () => {
    it('serves a precached page with no network at all', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        await boot(current, { caches });

        const worker = offline(current, caches);
        const body = await visit(worker, ABOUT);

        // The network is tried first and fails — `freshness` means fresh when possible —
        // and the payload comes back anyway.
        expect(body.status).toBe(200);
        await expect(body.json()).resolves.toEqual({ template: '<p>/about</p>' });
    });

    it('asks for the payload, not the page', async () => {
        const worker = await boot();
        const sent = worker.sentRequest(ABOUT);

        expect(sent).toBeDefined();
        expect(sent.headers.get('X-Pwax-Component')).toBe('true');
        expect(sent.headers.get('Accept')).toBe('application/json');
        expect(sent.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    });

    it('passes cookies through, since caches are shared', async () => {
        const worker = await boot();

        expect(worker.sentRequest(ABOUT).credentials).toBe('same-origin');
    });

    it('stores a page under a key carrying the headers its Vary names', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        await boot(current, { caches });

        const pages = await caches.open(`pwax-pages-h1`);

        // The mechanism, not the outcome: a bare lookup must miss, because the stored key
        // carries headers the bare request does not.
        await expect(pages.match(new Request(ABOUT))).resolves.toBeUndefined();
        await expect(pages.match(asRuntime(ABOUT))).resolves.toBeDefined();
    });

    it('refuses to store a page that answered with HTML', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        // A route behind `auth` redirects to a login screen; `fetch` follows it silently
        // and the result is a perfectly `ok` HTML response. Storing it would pin the login
        // page as this route's payload forever.
        const worker = createWorker({
            manifest: current,
            caches,
            routes: (path) =>
                path === '/sw.json'
                    ? Response.json(current)
                    : new Response('<html>login</html>', {
                          headers: { 'Content-Type': 'text/html', 'Cache-Control': 'public' },
                      }),
        });

        await worker.dispatch('install');
        await worker.dispatch('activate');

        const pages = await caches.open('pwax-pages-h1');

        await expect(pages.match(asRuntime(ABOUT))).resolves.toBeUndefined();
    });

    it('caches a page visited at runtime, so anywhere you have been works offline', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, { caches, cacheable: [ABOUT, DASHBOARD] });

        await worker.dispatch('fetch', {
            request: asRuntime(DASHBOARD),
            preloadResponse: Promise.resolve(null),
        });

        const later = offline(current, caches);
        const response = await later.dispatch('fetch', {
            request: asRuntime(DASHBOARD),
            preloadResponse: Promise.resolve(null),
        });

        await expect((await response).json()).resolves.toEqual({ template: '<p>/dashboard</p>' });
    });

    /**
     * The payload alone is enough to work offline, but it means a spinner and a second
     * round trip on a device that already has everything. The document has the component
     * inlined in `pwax-initial`, so the page paints at once.
     */
    it("answers an offline navigation with the page's own document", async () => {
        const current = manifest();
        const caches = new FakeCaches();

        await boot(current, { caches });

        const response = await offline(current, caches).navigate(ABOUT);

        await expect((await response).text()).resolves.toContain('pwax-initial');
    });

    it('falls back to the shell for a page it has no document for', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, { caches, cacheable: [ABOUT, DASHBOARD] });

        // Visited, so its payload is cached — but no document was ever precached for it.
        await visit(worker, DASHBOARD);

        const response = await offline(current, caches).navigate(DASHBOARD);

        await expect((await response).text()).resolves.toContain('pwax-initial');
    });

    it('never answers a navigation with a page payload', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        await boot(current, { caches });

        const worker = offline(current, caches);
        const response = await worker.navigate(ABOUT);

        // HTML, never the JSON payload that is also stored for this URL. A document
        // request answered with JSON is a download prompt.
        const body = await (await response).text();

        expect(body).toContain('<html');
        expect(body).not.toContain('template');
    });

    it('serves the same cached page to a second visitor offline', async () => {
        // Caches are shared: a page that the first visitor fetched is the page the second
        // visitor gets. This used to require a per-identity wipe and a re-fetch, which
        // cost both cache space and the offline experience.
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, { caches, cacheable: [ABOUT, DASHBOARD] });

        await visit(worker, DASHBOARD);

        const response = await visit(offline(current, caches), DASHBOARD);

        expect(response.status).toBe(200);
        await expect(response.json()).resolves.toEqual({ template: '<p>/dashboard</p>' });
    });

    it('caches a page the route never marked cacheable, which is the default', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        // `cacheable: []` — every page answers `no-store, private`, exactly as
        // ComponentResponse does when a route says nothing.
        const worker = await boot(current, { caches, cacheable: [] });

        await visit(worker, DASHBOARD);

        const body = await visit(offline(current, caches), DASHBOARD);

        expect(body.status).toBe(200);
        await expect(body.json()).resolves.toEqual({ template: '<p>/dashboard</p>' });
    });

    it('precaches a no-store page too, since it is fetched the way the runtime asks', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        await boot(current, { caches, cacheable: [] });

        const pages = await caches.open('pwax-pages-h1');

        await expect(pages.match(asRuntime(ABOUT), { ignoreVary: true })).resolves.toBeDefined();
    });

    /**
     * A CDN-hosted framework could be precached in full and still not start offline: the
     * fetch handler returned early for any other origin, before it looked in the cache it
     * had just filled.
     */
    it('serves a precached third-party asset offline', async () => {
        const cdn = 'https://cdn.example/vue.js';
        const current = manifest({
            assetGroups: [{ name: 'app', installMode: 'prefetch', urls: [RUNTIME, SHELL, cdn] }],
            crossOrigin: [cdn],
            critical: [RUNTIME, SHELL],
        });
        const caches = new FakeCaches();

        const worker = createWorker({
            manifest: current,
            caches,
            routes: (path) =>
                path === '/sw.json'
                    ? Response.json(current)
                    : new Response('cdn-body', { headers: { 'Cache-Control': 'public' } }),
        });

        await worker.dispatch('install');
        await worker.dispatch('activate');

        const later = offline(current, caches);
        const response = await later.dispatch('fetch', {
            request: new Request(cdn),
            preloadResponse: Promise.resolve(null),
        });

        await expect((await response).text()).resolves.toBe('cdn-body');
    });

    it('leaves third-party requests it did not precache entirely alone', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, { caches });

        const response = await worker.dispatch('fetch', {
            request: new Request('https://analytics.example/t.gif'),
            preloadResponse: Promise.resolve(null),
        });

        // Not intercepted at all — no `respondWith`, so the browser handles it.
        expect(response).toBeUndefined();
    });

    it('never stores a page the server marked X-Pwax-Cache: none', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        // ->offline(false): stronger than omitting ->cacheable(), which only declines to
        // precache. This must be refused by the runtime cache too.
        const worker = createWorker({
            manifest: current,
            caches,
            routes: (path, request) => {
                if (path === '/sw.json') {
                    return Response.json(current);
                }

                if (path === SHELL) {
                    return new Response('<html>shell</html>', {
                        headers: { 'Content-Type': 'text/html', 'Cache-Control': 'public' },
                    });
                }

                if (path === DASHBOARD && request.headers.get('X-Pwax-Component') === 'true') {
                    return new Response('{"template":"secret"}', {
                        headers: {
                            'Content-Type': 'application/json',
                            'Cache-Control': 'private, max-age=600',
                            'X-Pwax-Cache': 'none',
                        },
                    });
                }

                return new Response('x', { headers: { 'Cache-Control': 'public' } });
            },
        });

        await worker.dispatch('install');
        await worker.dispatch('activate');
        await visit(worker, DASHBOARD);

        const pages = await caches.open('pwax-pages-h1');

        await expect(
            pages.match(asRuntime(DASHBOARD), { ignoreVary: true })
        ).resolves.toBeUndefined();
    });

    it('goes to the network first for a page when there is one', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, { caches });

        worker.fetches.length = 0;

        await worker.dispatch('fetch', {
            request: asRuntime(ABOUT),
            preloadResponse: Promise.resolve(null),
        });

        expect(worker.fetches).toHaveLength(1);
    });

    it('serves the cached payload without waiting under the performance strategy', async () => {
        const current = manifest({
            assetGroups: [
                { name: 'app', installMode: 'prefetch', urls: [RUNTIME, SHELL] },
                {
                    name: 'pages',
                    installMode: 'prefetch',
                    kind: 'page',
                    strategy: 'performance',
                    urls: [ABOUT],
                },
            ],
        });
        const caches = new FakeCaches();

        const worker = await boot(current, { caches });

        worker.fetches.length = 0;

        await worker.dispatch('fetch', {
            request: asRuntime(ABOUT),
            preloadResponse: Promise.resolve(null),
        });

        expect(worker.fetches).toEqual([]);
    });

    it('falls back to the cache when the network hangs past the timeout', async () => {
        const current = manifest({
            assetGroups: [
                { name: 'app', installMode: 'prefetch', urls: [RUNTIME, SHELL] },
                {
                    name: 'pages',
                    installMode: 'prefetch',
                    kind: 'page',
                    strategy: 'freshness',
                    timeout: 10,
                    urls: [ABOUT],
                },
            ],
        });
        const caches = new FakeCaches();

        await boot(current, { caches });

        const stalled = createWorker({
            manifest: current,
            caches,
            routes: () => new Promise(() => {}),
        });

        const response = await stalled.dispatch('fetch', {
            request: asRuntime(ABOUT),
            preloadResponse: Promise.resolve(null),
        });

        await expect((await response).json()).resolves.toEqual({ template: '<p>/about</p>' });
    });
});

describe('a server that is failing rather than absent', () => {
    /** A server that answers this path with a status instead of a payload. */
    const failing = (current, path, status) => (asked, request) =>
        asked === path
            ? new Response('<html>error</html>', {
                  status,
                  headers: { 'Content-Type': 'text/html' },
              })
            : server(current)(asked, request);

    it('serves the stored page when the origin cannot be reached', async () => {
        // Falling back only when `fetch` throws covers the network being gone and nothing
        // else. A proxy that cannot reach the application, or one that is mid-deploy,
        // resolves — and the visitor used to be shown an error while a copy of the page sat
        // on the device unread. 503 is what `php artisan down` answers with.
        const caches = new FakeCaches();
        const current = manifest();

        await boot(current, { caches });

        const broken = createWorker({
            manifest: current,
            caches,
            routes: failing(current, ABOUT, 503),
        });
        await broken.dispatch('activate');

        const response = await visit(broken, ABOUT);

        expect(response.status).toBe(200);
        await expect(response.json()).resolves.toEqual({ template: '<p>/about</p>' });
    });

    it('lets a 500 through, because the application ran and threw', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        await boot(current, { caches });

        const broken = createWorker({
            manifest: current,
            caches,
            routes: failing(current, ABOUT, 500),
        });
        await broken.dispatch('activate');

        // Answering this from cache hides it twice: the visitor sees a page that works and
        // reports nothing, and whoever deployed the bug has no idea a route is broken.
        expect((await visit(broken, ABOUT)).status).toBe(500);
    });

    it('does not answer a 404 from a stale copy', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        await boot(current, { caches });

        const gone = createWorker({
            manifest: current,
            caches,
            routes: failing(current, ABOUT, 404),
        });
        await gone.dispatch('activate');

        // The server is working correctly and saying something true. Answering from a
        // stored copy would invent a page that is not there any more.
        expect((await visit(gone, ABOUT)).status).toBe(404);
    });

    it('answers a navigation from the precached document when the origin returns a 5xx', async () => {
        // The rule has to hold on a reload too, not only on a payload fetch. A reload
        // during a deploy would otherwise replace an application that is installed on the
        // device with whatever error page the origin managed to produce.
        const caches = new FakeCaches();
        const current = manifest();

        await boot(current, { caches });

        const broken = createWorker({
            manifest: current,
            caches,
            routes: failing(current, ABOUT, 502),
        });
        await broken.dispatch('activate');

        const response = await broken.navigate(ABOUT);

        expect(response.status).toBe(200);
        await expect(response.text()).resolves.toContain('pwax-initial');
    });

    it('lets a navigation see a 404', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        await boot(current, { caches });

        const gone = createWorker({
            manifest: current,
            caches,
            routes: failing(current, ABOUT, 404),
        });
        await gone.dispatch('activate');

        expect((await gone.navigate(ABOUT)).status).toBe(404);
    });

    it('lets a navigation see a 500', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        await boot(current, { caches });

        const broken = createWorker({
            manifest: current,
            caches,
            routes: failing(current, ABOUT, 500),
        });
        await broken.dispatch('activate');

        // The reload that would have shown the error is the one a developer does when
        // something looks wrong. Answering it from the precache is how a broken deploy
        // survives the afternoon.
        expect((await broken.navigate(ABOUT)).status).toBe(500);
    });
});

/**
 * The other representation.
 *
 * A page answers two ways: JSON to the runtime, HTML to a navigation. Only the JSON was
 * ever stored after install, so a route the build did not precache — a dynamic one, or
 * anything route discovery could not reach — had no document at all and reloading it
 * offline fell back to the shell. With cookies passed through, the document the server
 * returns is what the worker stores.
 */
describe('documents cached as they are visited', () => {
    it('serves a route the build never precached', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, { caches, cacheable: [ABOUT, DASHBOARD] });

        // /dashboard is not in the manifest, so install stored no document for it.
        await worker.navigate(DASHBOARD);

        const response = await offline(current, caches).navigate(DASHBOARD);

        // The real page, inlined component and all — not the shell and a spinner.
        await expect(response.text()).resolves.toContain('pwax-initial');
    });

    it('stores a document rendered for any visitor', async () => {
        // No identity check: the document the server returns is the document the next
        // visitor gets. The old "must be anon" rule meant a missing header meant
        // unknown, and unknown meant nobody's, and a route that didn't say so had its
        // HTML thrown away.
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, { caches, cacheable: [ABOUT, DASHBOARD] });
        await worker.navigate(DASHBOARD);

        const response = await offline(current, caches).navigate(DASHBOARD);

        await expect(response.text()).resolves.toContain('pwax-initial');
    });

    it('stores nothing when runtime page caching is off', async () => {
        // `pages.runtime => false` is documented as the way to keep page content off disk
        // entirely, and a document is more page content than the payload is — the markup
        // rendered rather than described.
        const current = manifest({ pageRuntime: false });
        const caches = new FakeCaches();

        const worker = await boot(current, { caches, cacheable: [ABOUT, DASHBOARD] });
        await worker.navigate(DASHBOARD);

        expect(await caches.keys()).not.toContain('pwax-documents-h1');
    });

    it('drops the documents of a build that has been replaced', async () => {
        const caches = new FakeCaches();
        const first = manifest();

        const worker = await boot(first, { caches, cacheable: [ABOUT, DASHBOARD] });
        await worker.navigate(DASHBOARD);

        expect(await caches.keys()).toContain('pwax-documents-h1');

        // A document has the compiled component inlined, so one kept across a deploy would
        // paint the previous build's markup into this build's shell.
        await boot(manifest({ hash: 'h2' }), { caches, cacheable: [ABOUT, DASHBOARD] });

        expect(await caches.keys()).not.toContain('pwax-documents-h1');
    });
});
