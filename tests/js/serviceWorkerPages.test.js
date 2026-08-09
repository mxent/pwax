/**
 * Page payloads, offline.
 *
 * This is the file that covers the defect the whole offline story turned on: an
 * application whose components, framework and shell were all precached, that installed as
 * a PWA and booted offline — and then showed "This page needs an internet connection to
 * load", because the one thing never cached was the page itself.
 *
 * Three separate faults produced that, and each has a test here that isolates it:
 *
 *   1. The worker fetched page URLs without the header that asks for a payload, so the
 *      server answered with the HTML shell, which is `no-store`, which the worker
 *      correctly refused to store. Every page in `precache` was silently skipped.
 *   2. It stored entries under a bare URL, while page responses carry
 *      `Vary: X-Pwax-Component, X-Requested-With, Accept` — so no lookup by the runtime
 *      could ever match, even had step 1 worked.
 *   3. A page request is not a navigation, so it never reached the shell fallback; it
 *      fell through to the generic network-first path and became a network error.
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
            return new Response('<html>shell</html>', {
                headers: { 'Content-Type': 'text/html', 'Cache-Control': 'public' },
            });
        }

        if (path === ABOUT || path === DASHBOARD) {
            const wants = request && request.headers.get('X-Pwax-Component') === 'true';

            if (!wants) {
                // What ComponentResponse::shell() renders: the SPA shell with the
                // component inlined in a `pwax-initial` island. `no-store` because it
                // would carry a CSRF token for a session — there is none here, since the
                // worker asks without cookies.
                return new Response(
                    `<html><div id="pwax"></div><script id="pwax-initial">{"url":"${path}"}</script></html>`,
                    {
                        headers: { 'Content-Type': 'text/html', 'Cache-Control': 'no-store, private' },
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

        return new Response(`body:${path}`, { headers: { 'Cache-Control': 'private, max-age=3600' } });
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
const asRuntime = (path, identity) =>
    new Request(path, {
        headers: identity ? { ...PAGE_HEADERS, 'X-Pwax-Identity': identity } : PAGE_HEADERS,
    });

/** Ask for a page the way the runtime does. */
const visit = (worker, path, identity) =>
    worker.dispatch('fetch', {
        request: asRuntime(path, identity),
        preloadResponse: Promise.resolve(null),
    });

/**
 * The worker answers a request it cannot satisfy with `Response.error()` rather than a
 * rejection — same outcome for the caller, without an unhandled rejection attributed to
 * the worker. `type: 'error'` is how that arrives.
 */
async function expectNetworkError(response) {
    const settled = await response;

    expect(settled.type).toBe('error');
}

describe('page payloads offline', () => {
    it('serves a precached page with no network at all', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        await boot(current, { caches });

        const worker = offline(current, caches);
        const body = await visit(worker, ABOUT);

        // The network is tried first and fails — `freshness` means fresh when possible —
        // and the payload comes back anyway. Before this fix the same request produced a
        // network error, which the runtime rendered as "This page needs an internet
        // connection to load" on a device that had the whole application installed.
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

    it('precaches pages without cookies, so it stores the guest rendering', async () => {
        const worker = await boot();

        expect(worker.sentRequest(ABOUT).credentials).toBe('omit');
    });

    it('stores a page under a key carrying the headers its Vary names', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        await boot(current, { caches });

        const pages = await caches.open(`pwax-pages-v1-h1-install`);

        // The mechanism, not the outcome: a bare lookup must miss, because the stored key
        // carries headers the bare request does not. This is the assertion that fails if
        // anyone ever goes back to `cache.put(url, response)`.
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

        const pages = await caches.open('pwax-pages-v1-h1-install');

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
    it('answers an offline navigation with the page\'s own document', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        await boot(current, { caches });

        const response = await offline(current, caches).navigate(ABOUT);

        await expect((await response).text()).resolves.toContain('pwax-initial');
    });

    it('fetches the document without cookies, like the payload', async () => {
        const worker = await boot();

        const documents = worker.requests.filter(
            (r) => new URL(r.url).pathname === ABOUT && r.headers.get('X-Pwax-Component') !== 'true'
        );

        expect(documents).toHaveLength(1);
        expect(documents[0].credentials).toBe('omit');
    });

    /**
     * Ordering that carries weight: a route behind auth answers an anonymous request with
     * a login screen. As JSON that is detectable and refused; as HTML it is
     * indistinguishable from a real page. Requiring the payload to succeed first is what
     * stops a login screen being cached as somebody's Settings page.
     */
    it('does not store a document when the payload was refused', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = createWorker({
            manifest: current,
            caches,
            routes: (path, request) => {
                if (path === '/sw.json') {
                    return Response.json(current);
                }

                if (path === ABOUT) {
                    // A login screen, whichever way it is asked for.
                    return new Response('<html>login</html>', {
                        headers: { 'Content-Type': 'text/html', 'Cache-Control': 'public' },
                    });
                }

                return new Response('<html>shell</html>', {
                    headers: { 'Content-Type': 'text/html', 'Cache-Control': 'public' },
                });
            },
        });

        await worker.dispatch('install');
        await worker.dispatch('activate');

        const precache = await caches.open('pwax-precache-v1-h1');

        await expect(precache.match(ABOUT)).resolves.toBeUndefined();
    });

    it('falls back to the shell for a page it has no document for', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, { caches, cacheable: [ABOUT, DASHBOARD] });

        // Visited, so its payload is cached — but no document was ever precached for it.
        await visit(worker, DASHBOARD);

        const response = await offline(current, caches).navigate(DASHBOARD);

        await expect((await response).text()).resolves.toBe('<html>shell</html>');
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

    it('does not serve one identity a page cached for another', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, { caches, cacheable: [ABOUT, DASHBOARD] });

        await visit(worker, DASHBOARD, 'alice');

        await expectNetworkError(visit(offline(current, caches), DASHBOARD, 'bob'));
    });

    it('serves what the build precached to a signed-in visitor too', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        await boot(current, { caches });

        // This used to be refused, on the reasoning that a cookieless rendering belongs
        // to the guest it was rendered for. The reasoning does not survive contact with
        // the rest of the worker: the precached *document* for this same URL is already
        // served to whoever asks, so a signed-in visitor offline could reload `/about`
        // and see it, but could not click a link to it. Refusing the payload withheld
        // nothing and broke the feature precaching exists for.
        const response = await visit(offline(current, caches), ABOUT, 'alice');

        expect(response.type).not.toBe('error');
        expect((await response.json()).template).toContain('about');
    });

    it('forgets one identity without touching the precache or another identity', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        const worker = await boot(current, { caches, cacheable: [ABOUT, DASHBOARD] });

        for (const who of ['alice', 'bob']) {
            await worker.dispatch('fetch', {
                request: asRuntime(DASHBOARD, who),
                preloadResponse: Promise.resolve(null),
            });
        }

        await worker.dispatch('message', { data: { type: 'PWAX_FORGET_IDENTITY', identity: 'alice' } });

        expect(await caches.has('pwax-pages-v1-h1-alice')).toBe(false);
        expect(await caches.has('pwax-pages-v1-h1-bob')).toBe(true);
        expect(await caches.has('pwax-precache-v1-h1')).toBe(true);
    });

    /**
     * The case that shipped broken.
     *
     * A page payload is `no-store, private` unless the route calls `->cacheable()`, which
     * is the default for every route in a normal application. Runtime page caching gated
     * its writes on the same rule assets use, so it stored nothing at all: the pages cache
     * stayed empty, and offline gave a shell that could render nothing. The earlier test
     * for this passed only because its fixture opted in.
     */
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

    it('precaches a no-store page too, since it is fetched without cookies', async () => {
        const current = manifest();
        const caches = new FakeCaches();

        await boot(current, { caches, cacheable: [] });

        const pages = await caches.open('pwax-pages-v1-h1-install');

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

        const pages = await caches.open('pwax-pages-v1-h1-anon');

        await expect(pages.match(asRuntime(DASHBOARD), { ignoreVary: true })).resolves.toBeUndefined();
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

describe('a precached page and a signed-in visitor', () => {
    /** Whatever `Shell::identity()` mints. Sixteen hex characters, opaque to the worker. */
    const ALICE = 'a1b2c3d4e5f60718';

    const signedIn = { headers: { ...PAGE_HEADERS, 'X-Pwax-Identity': ALICE } };

    it('serves the precached payload offline', async () => {
        // Reported: sign in, browse online, go offline, click a link to a page you have
        // not opened this session — and it errors, while *reloading* that same URL works.
        //
        // Reloading worked because a navigation is answered from the precache, which is
        // shared. The payload was precached too, but under `anon`, and a signed-in
        // visitor's requests name their own cache — so the one thing precaching exists to
        // provide was the one thing they could not reach.
        const caches = new FakeCaches();

        await boot(manifest(), { caches });

        const offline = createWorker({
            manifest: manifest(),
            caches,
            routes: server(manifest(), { down: new Set([ABOUT]) }),
        });
        await offline.dispatch('activate');

        const response = await offline.request(ABOUT, signedIn);

        expect(response.type).not.toBe('error');
        expect((await response.json()).template).toContain('about');
    });

    it('still prefers the visitor\'s own copy when they have one', async () => {
        const caches = new FakeCaches();

        const online = await boot(manifest(), { caches });
        await online.request(DASHBOARD, signedIn);

        const offline = createWorker({
            manifest: manifest(),
            caches,
            routes: server(manifest(), { down: new Set([DASHBOARD]) }),
        });
        await offline.dispatch('activate');

        // Theirs, rendered with their session, not the guest copy.
        expect((await (await offline.request(DASHBOARD, signedIn)).json()).template).toContain(
            'dashboard'
        );
    });
});

describe('a server that is failing rather than absent', () => {
    /** A server that answers this path with a status instead of a payload. */
    const failing = (current, path, status) => (asked, request) =>
        asked === path
            ? new Response('<html>error</html>', { status, headers: { 'Content-Type': 'text/html' } })
            : server(current)(asked, request);

    it('serves the stored page when the origin returns a 5xx', async () => {
        // Falling back only when `fetch` throws covers the network being gone and nothing
        // else. A connection that drops mid-request, a proxy in between, an application
        // that is deploying — all resolve, with a 5xx, and the visitor used to be shown an
        // error while a copy of the page sat on the device unread.
        const caches = new FakeCaches();
        const current = manifest();

        await boot(current, { caches });

        const broken = createWorker({ manifest: current, caches, routes: failing(current, ABOUT, 503) });
        await broken.dispatch('activate');

        const response = await visit(broken, ABOUT);

        expect(response.status).toBe(200);
        await expect(response.json()).resolves.toEqual({ template: '<p>/about</p>' });
    });

    it('does not answer a 404 from a stale copy', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        await boot(current, { caches });

        const gone = createWorker({ manifest: current, caches, routes: failing(current, ABOUT, 404) });
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

        const broken = createWorker({ manifest: current, caches, routes: failing(current, ABOUT, 502) });
        await broken.dispatch('activate');

        const response = await broken.navigate(ABOUT);

        expect(response.status).toBe(200);
        await expect(response.text()).resolves.toContain('pwax-initial');
    });

    it('lets a navigation see a 404', async () => {
        const caches = new FakeCaches();
        const current = manifest();

        await boot(current, { caches });

        const gone = createWorker({ manifest: current, caches, routes: failing(current, ABOUT, 404) });
        await gone.dispatch('activate');

        expect((await gone.navigate(ABOUT)).status).toBe(404);
    });
});
