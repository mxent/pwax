/**
 * The Pwax service worker.
 *
 * Driven by the asset manifest at `sw.json`: the server enumerates every URL the
 * application is made of with a content hash, and this installs the lot in one pass. A
 * visitor who has loaded one page can go offline and still reach every route and every
 * component.
 *
 * Built to `dist/pwax-sw.js` and served with a small generated preamble in front of it,
 * which is where everything the server decided comes from. Ordinary JavaScript rather than
 * a Blade template on purpose: 1,600 lines that no linter, formatter or minifier ever saw,
 * testable only through a hand-written Blade emulator, is a bad trade for a server-injected
 * surface this small.
 *
 * To add behaviour of your own — a `push` handler, a `sync` handler — use
 * `service_worker.extend` rather than forking this file. Those scripts are concatenated
 * after it and share its scope.
 */

import { registerPush, registerSync } from './push.js';

/**
 * Everything the server decided, injected ahead of this bundle.
 *
 * Concatenated rather than fetched with `importScripts`: that would cost a synchronous
 * blocking request during install, and a stale HTTP-cached bundle could pair a new
 * preamble with an old worker. One byte stream cannot come apart that way.
 */
const {
    manifestUrl: MANIFEST_URL,
    manifestHash: MANIFEST_HASH,
    prefix: PREFIX,
    config: CONFIG,
} = self.__PWAX_SW__;

/** Third-party URLs this build precached, and the only ones we answer off-origin. */
const CROSS_ORIGIN = new Set(CONFIG.crossOrigin || []);

/**
 * How many assets to precache at once.
 *
 * Six, matching what a browser opens to one origin anyway. The number that matters is
 * that it is bounded: PHP's built-in server — `php artisan serve`, what most people
 * develop against — handles one request at a time.
 */
const CONCURRENCY = CONFIG.concurrency || 6;

const STATE_CACHE = `${PREFIX}-state`;

/** The manifest the active worker is serving. */
const STATE_KEY = '/__pwax__/sw-state';

/**
 * The manifest a worker has installed but not yet activated.
 *
 * Kept apart from the active one on purpose. A worker can be terminated between `install`
 * and `activate`, and one that woke up to activate with only the *active* pointer to read
 * would conclude that the cache it had just built was stale and delete it. Writing to the
 * active pointer at install time is not the answer either: the worker still in control
 * would then start serving assets from a cache belonging to a build its pages are not
 * running.
 */
const PENDING_KEY = '/__pwax__/sw-pending';

/**
 * The last resort: a navigation with no network and nothing stored for that URL.
 *
 * Rendered server-side from `pwax::js.offline` and handed over in the preamble, so it
 * carries the application's own `lang` and `dir` and can be published and edited like any
 * other view — rather than forty lines of HTML and CSS inside a JavaScript string here,
 * unlintable, unformattable and hardcoded to `lang="en"`.
 */
const OFFLINE_HTML = CONFIG.offlineHtml || '<!DOCTYPE html><title>Offline</title>';

/**
 * The request the client runtime would make for a page — and therefore the cache key.
 *
 * Page responses carry `Vary: X-Pwax-Component, X-Requested-With, Accept`, so an entry
 * stored under a bare URL can never be matched by the runtime's request. Storing it under
 * this Request is what makes ordinary Vary matching succeed, rather than relying on
 * `ignoreVary` to paper over a key that never agreed.
 */
function pageKey(url) {
    return new Request(url, { headers: CONFIG.pageHeaders || {} });
}

/**
 * The same request, aimed at the network.
 *
 * Used to fetch page payloads and documents at install. The default credentials mode lets
 * the request pass cookies through, so precaching stores whatever the server actually
 * rendered for the request.
 */
function pageRequest(url, credentials) {
    return new Request(url, {
        headers: CONFIG.pageHeaders || {},
        credentials: credentials || 'same-origin',
        cache: 'reload',
    });
}

/** Set by `install`, so `activate` does not have to rediscover what it just built. */
let installed = null;

/** Memoised read of the active manifest, for the fetch handler. */
let statePromise = null;

self.addEventListener('install', (event) => {
    // Deliberately no `skipWaiting()` here.
    //
    // Calling it means the new worker activates the moment it finishes installing, takes
    // control of every open tab, and — because the client reloads on `controllerchange` —
    // reloads all of them mid-session, discarding whatever the user was typing. It also
    // makes `registration.waiting` unobservable, so the `pwax:update-available` prompt
    // can never fire. The page asks for activation instead, via PWAX_SKIP_WAITING.
    event.waitUntil(install());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(activate());
});

self.addEventListener('message', (event) => {
    const type = event.data && event.data.type;

    if (type === 'PWAX_SKIP_WAITING') {
        self.skipWaiting();
        return;
    }

    if (type === 'PWAX_CLEAR_CACHES') {
        event.waitUntil(clearCaches().then(() => reply(event, { type: 'PWAX_CACHES_CLEARED' })));
        return;
    }
});

// Registered unconditionally. A `push` listener that never receives a push costs nothing,
// and gating registration on config would mean push cannot start working until the deploy
// *after* the one that enabled it.
registerPush(CONFIG);
registerSync(CONFIG, syncName());

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Only GET is cacheable, and only our own origin is ours to cache. Range requests are
    // skipped because a partial response cached as a whole one corrupts media playback.
    if (request.method !== 'GET' || request.headers.has('range')) {
        return;
    }

    if (new URL(request.url).origin !== self.location.origin) {
        // Third-party requests are none of our business — with one exception. An asset the
        // manifest deliberately precached from another origin, a CDN copy of Vue under
        // `assets.source = 'cdn'` or anything in `pwax.scripts` pointing off-site, was
        // being fetched at install and then never served from that cache: the handler
        // returned here before looking. A CDN-hosted framework could not start offline at
        // all, however completely it had been precached.
        //
        // Matched against a list inlined into this file rather than read from the stored
        // manifest, because `respondWith` has to be called synchronously and everything
        // not on the list must be left entirely alone.
        if (CROSS_ORIGIN.has(request.url)) {
            event.respondWith(crossOriginAsset(event));
        }

        return;
    }

    event.respondWith(route(event));
});

/** A precached third-party asset, falling back to the network. */
async function crossOriginAsset(event) {
    try {
        const manifest = await state();
        const precache = await precacheFor(manifest);
        const hit = precache && (await precache.match(event.request, { ignoreVary: true }));

        return hit || (await fetch(event.request));
    } catch {
        return fetch(event.request);
    }
}

/* ---------------------------------------------------------------- installation ----- */

async function install() {
    const manifest = await fetchManifest();

    // No manifest means no network, and there is nothing to install without one. Throwing
    // leaves the previous worker in charge and lets the browser retry, which is the right
    // outcome — an install that "succeeded" with an empty cache is worse than none.
    if (!manifest || !manifest.hash) {
        throw new Error('pwax sw: could not fetch the asset manifest, refusing to install');
    }

    const cacheName = precacheName(manifest);

    // Opened together. Nothing here depends on anything else here — the cache and pages
    // are named from the manifest and the previous precaches are found by listing — so
    // awaiting them in turn only added the storage layer's latency up.
    const [cache, pages, documents, previous] = await Promise.all([
        caches.open(cacheName),
        caches.open(pagesName(manifest)),
        // `pages.runtime => false` is the stronger statement of the two — it is documented
        // as keeping rendered page markup off disk entirely, and `rememberDocument` already
        // honours it — so it overrides `pages.documents` rather than being ignored by a
        // deploy that then writes a document for every route.
        manifest.pageDocuments === false || manifest.pageRuntime === false
            ? null
            : caches.open(documentsName(manifest)),
        previousPrecaches(cacheName),
    ]);

    const inherited = await inheritedHashes(previous);

    const entries = prefetchEntries(manifest);
    const urls = entries.map((entry) => entry.url);
    const critical = new Set(manifest.critical || []);
    const crossOrigin = new Set(manifest.crossOrigin || []);

    // Individually, not with `cache.addAll`. `addAll` is atomic: one 404 anywhere in the
    // list rejects the whole thing, and the usual `.catch(console.warn)` around it then
    // activates a worker with an entirely empty cache while reporting success.
    //
    // And a few at a time, not all at once. An application with a hundred components
    // produces a hundred requests, and `php artisan serve` handles one at a time — so an
    // install would queue behind itself until connections started being refused, which
    // looks exactly like the app being broken.
    const results = await settleWithLimit(entries, CONCURRENCY, async (entry) => {
        const stored = await store(entry.kind === 'page' ? pages : cache, entry.url, {
            hash: (manifest.hashTable || {})[entry.url],
            inherited,
            crossOrigin: crossOrigin.has(entry.url),
            kind: entry.kind,
            credentials: entry.credentials,
        });

        // Both halves, not one. A page answers two ways — a payload to the runtime, a
        // document to a browser navigation — and precaching only the payload meant a route
        // the visitor had never opened had no HTML at all. Offline, and on a cold start,
        // that is a shell and a spinner while the runtime fetches a payload it already
        // has, instead of the document the server already rendered.
        if (entry.kind === 'page' && documents) {
            await storeDocument(documents, entry.url, entry.credentials);
        }

        return stored;
    });

    const failures = urls.filter((_, i) => results[i].status === 'rejected');

    // Kept apart from failures. A response the server marked `no-store` was not lost, it
    // was refused — every page rendered by `pwaxRender()` says exactly that, on
    // purpose, so that one visitor's HTML is never written to another's disk. Reporting
    // that as "could not be precached" reads like a broken install and sends people
    // looking for a network problem that is not there.
    const skipped = urls.filter(
        (_, i) => results[i].status === 'fulfilled' && results[i].value === false
    );
    const fatal = [...failures, ...skipped].filter((url) => critical.has(url));

    if (failures.length) {
        console.warn(
            `pwax sw: ${failures.length} of ${urls.length} assets could not be fetched. ` +
                'Run `php artisan pwax:precache --verify` to find views that cannot render ' +
                'without controller data.',
            failures
        );
    }

    if (skipped.length) {
        console.info(
            `pwax sw: ${skipped.length} URL(s) were not stored. An asset the server marked ` +
                '`Cache-Control: no-store`, a page marked ->offline(false), or a page that ' +
                'answered with something other than JSON — a route behind auth redirects to ' +
                'a login screen when asked for without cookies, which is refused on purpose. ' +
                'Run `php artisan pwax:precache --verify`.',
            skipped
        );
    }

    if (fatal.length) {
        // Better to keep the worker that is already working than to replace it with one
        // that has no shell and no runtime and would answer every offline navigation
        // with an error page.
        await caches.delete(cacheName);
        throw new Error(`pwax sw: required assets missing (${fatal.join(', ')})`);
    }

    // Stored inside the cache it describes, so a later install can read the hashes this
    // build was made from and copy the entries that have not changed.
    await cache.put(
        MANIFEST_URL,
        new Response(JSON.stringify(manifest), {
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
        })
    );

    await reconcileLazy(manifest, inherited);

    installed = manifest;

    await writeManifest(PENDING_KEY, manifest);
}

/**
 * Bring the lazy cache into line with the build being installed.
 *
 * The lazy cache outlives a deploy on purpose, so something has to notice when a file in
 * it is no longer the file the application ships. The manifest hash cannot: it changes
 * when *anything* changes, and renaming the cache on it would be the whole-cache discard
 * this exists to avoid. So the hash tables are compared entry by entry, and only genuinely
 * changed URLs are acted on.
 *
 * What happens to a changed file is what `asset_groups[].update_mode` selects between —
 * and until now it selected between nothing, since the key reached the manifest, was never
 * read, and changed the manifest hash on its way past. `prefetch` (the default) brings the
 * file up to date here, so the visitor never waits for it. `lazy` drops it and lets the
 * next request fetch it.
 *
 * Only files the device already holds are considered. Fetching one it never asked for
 * would make the group `install_mode: prefetch` by the back door, which is the setting
 * directly above and the one the developer declined. A URL the new manifest does not list
 * at all is dropped whatever the mode: it is not part of the application any more.
 *
 * Done at install rather than activate, because this is where fetching belongs and where
 * failure is already tolerated. It does mean a lazily-held image can update just before
 * the new worker takes over — which is the harmless direction, and much better than
 * serving a file the build no longer contains.
 */
async function reconcileLazy(manifest, inherited) {
    const cache = await openIfPresent(lazyName());

    if (!cache) {
        return;
    }

    const groups = (manifest.assetGroups || []).filter(
        (group) => group.installMode === 'lazy' && group.kind !== 'page'
    );

    const hashes = manifest.hashTable || {};
    const drop = [];
    const refresh = [];

    for (const key of await cache.keys()) {
        const url = new URL(key.url);
        const path = url.pathname + url.search;
        const hash = hashes[path];
        const was = inherited.hashes.get(path);

        // Unhashed both times means no manifest ever had an opinion about this file — a
        // pattern-matched URL no glob resolved to a real path. Leave it alone.
        if (hash === undefined && was === undefined) {
            continue;
        }

        if (hash === undefined) {
            drop.push(key);
            continue;
        }

        if (hash === was) {
            continue;
        }

        const group = groups.find(
            (candidate) =>
                (candidate.urls || []).includes(path) || matchesAny(candidate.patterns, path)
        );

        group && group.updateMode !== 'lazy' ? refresh.push(path) : drop.push(key);
    }

    await Promise.all(drop.map((key) => cache.delete(key)));

    // Same limiter as the main install pass, and the same tolerance. A refresh that fails
    // leaves the old copy in place, which is staler than intended and much better than
    // nothing — it must never be able to fail an install.
    await settleWithLimit(refresh, CONCURRENCY, async (path) => {
        const response = await fetch(new Request(path, { cache: 'reload' }));

        if (response.ok && cacheable(response) && !tooLarge(response, manifest)) {
            await cache.put(path, response);
        }
    });
}

async function activate() {
    const manifest =
        installed || (await readManifest(PENDING_KEY)) || (await readManifest(STATE_KEY)) || CONFIG;
    const keep = new Set([
        precacheName(manifest),
        pagesName(manifest),
        documentsName(manifest),
        runtimeName(),
        lazyName(),
        syncName(),
        STATE_CACHE,
    ]);

    // The data caches are matched by prefix in the sweep below, not by name, since one
    // data group can be added or removed across deploys and we want the rest to survive.
    for (const group of manifest.dataGroups || []) {
        keep.add(dataName(group));
    }

    // Promoted before the sweep, so a worker terminated midway through cannot come back
    // and mistake the cache it is activating for a stale one.
    await writeManifest(STATE_KEY, manifest);
    statePromise = Promise.resolve(manifest);

    // Only our own caches. Another worker, or another library, may own caches on this
    // origin and deleting those would be someone else's outage.
    const keys = await caches.keys();

    const stale = keys.filter((key) => key.startsWith(`${PREFIX}-`) && !keep.has(key));

    await Promise.all(stale.map((key) => caches.delete(key)));

    // A cache that was just deleted is not the size the counter remembers.
    counts.clear();

    await deleteManifest(PENDING_KEY);

    if (manifest.navigationPreload !== false && self.registration.navigationPreload) {
        await self.registration.navigationPreload.enable();
    }

    await self.clients.claim();
}

/**
 * Put one URL into the precache, reusing the previous cache's copy when its hash matches.
 *
 * This is what keeps a deploy cheap. A release that changed one component leaves every
 * other hash identical, so everything else is copied between caches rather than
 * re-downloaded — the difference between a few kilobytes and the whole application on
 * every deploy, over whatever connection the visitor happens to have.
 */
async function store(cache, url, { hash, inherited, crossOrigin, kind, credentials }) {
    const page = kind === 'page';

    if (hash && inherited.hashes.get(url) === hash) {
        const old = inherited.holder.get(url);
        const copy = old && (await old.match(url));

        if (copy) {
            await cache.put(page ? pageKey(url) : url, copy);
            return true;
        }
    }

    let request;

    if (page) {
        request = pageRequest(url, credentials);
    } else if (crossOrigin) {
        request = new Request(url, { cache: 'reload', mode: 'cors', credentials: 'omit' });
    } else {
        request = new Request(url, { cache: 'reload' });
    }

    const response = await fetch(request);

    // A response that arrived but must not be kept is reported by returning false, not by
    // throwing: it is a policy decision, not a failure, and conflating the two turns a
    // correctly-working install into an alarming one.
    if (!response.ok || response.status === 206) {
        throw new Error(`pwax sw: ${url} responded ${response.status}`);
    }

    // A page must actually be a payload. `fetch` follows redirects silently, so a route
    // behind `auth` answers 200 with the login page's HTML — perfectly `ok`, and useless
    // as a cached page. Storing it would serve the login screen for that route forever.
    if (page && !isJson(response)) {
        return false;
    }

    if (!(page ? storablePage(response) : cacheable(response))) {
        return false;
    }

    // Pages are keyed by the request the runtime will make, not by the bare URL, so the
    // response's `Vary` can be satisfied on lookup.
    await cache.put(page ? pageKey(url) : url, response);

    return true;
}

/**
 * Fetch a page's *document* — the HTML a browser navigation gets — and keep it.
 *
 * The other half of {@link store} for a page entry. Same URL, no `X-Pwax-Component`
 * header, so the application renders the shell with the component inlined rather than
 * returning a JSON payload.
 *
 * Never throws. A document is an improvement on a page that already works offline through
 * its payload, so nothing here is allowed to fail an install: a route behind `auth` that
 * redirects to a login screen, a page that opted out with `->offline(false)`, a disk that
 * is full — all of them mean "no document for this route", and the payload is still there.
 */
async function storeDocument(cache, url, credentials) {
    try {
        const response = await fetch(
            new Request(url, {
                credentials: credentials || 'same-origin',
                cache: 'reload',
                headers: { Accept: 'text/html' },
            })
        );

        // `redirected` is the login-screen case: `fetch` follows it silently, so the
        // response is a perfectly `ok` document for the wrong URL. Storing it would serve
        // the login page under this route until the next deploy.
        if (!response.ok || response.redirected || !isHtml(response) || !storablePage(response)) {
            return false;
        }

        // Keyed by path, which is what `storedDocument()` looks up and what
        // `rememberDocument()` writes on a real visit — so an install and a visit put the
        // same thing in the same place.
        await cache.put(new URL(url, self.location.origin).pathname, response);

        return true;
    } catch {
        return false;
    }
}

/**
 * Statuses that mean the application never answered, as opposed to answering badly.
 *
 * This is the line between "fall back to what is stored" and "show the visitor what the
 * server said", and it is not all of 5xx. A **500 is the application running and
 * throwing** — a real bug in a real route — and answering it from cache hides it twice
 * over: the visitor sees a page that works and reports nothing, and whoever deployed it
 * has no idea a route is broken until somebody eventually notices. A stale page is worse
 * than an error page when the error is the thing you needed to know.
 *
 * These three are the origin not being reachable through:
 *
 *   502  a proxy could not get an answer out of the application at all
 *   503  the application, or the proxy, is explicitly refusing for now — `php artisan
 *        down` is a 503, so this is the deploy window
 *   504  a proxy waited and gave up
 *
 * From the device none of those is distinguishable from a bad connection, which is exactly
 * the case the fallback exists for. Everything else — 500 included, and every 4xx — is the
 * server working and saying something true, so it goes through untouched.
 */
const UNREACHABLE = [502, 503, 504];

function unreachable(response) {
    return UNREACHABLE.indexOf(response.status) !== -1;
}

/**
 * Say when a stored copy stood in for a failing origin.
 *
 * Silence here is how an outage goes unnoticed: the application carries on looking healthy
 * because every visitor is being served yesterday's copy of it. One line, with the status
 * and the URL, so the failure is visible to anybody with devtools open.
 */
function warnStale(url, status) {
    console.warn(
        `pwax sw: ${url} answered ${status}; served a stored copy instead. ` +
            'The origin is not reachable — this is being hidden from the page.'
    );
}

function isJson(response) {
    return (response.headers.get('Content-Type') || '').includes('json');
}

/**
 * `Promise.allSettled` with a ceiling on how many run at once.
 *
 * Same result shape, so failures are still counted rather than thrown.
 */
async function settleWithLimit(items, limit, fn) {
    const results = new Array(items.length);
    let cursor = 0;

    const worker = async () => {
        for (;;) {
            const index = cursor++;

            if (index >= items.length) {
                return;
            }

            try {
                results[index] = { status: 'fulfilled', value: await fn(items[index]) };
            } catch (reason) {
                results[index] = { status: 'rejected', reason };
            }
        }
    };

    await Promise.all(Array.from({ length: Math.min(limit, items.length) }, worker));

    return results;
}

/**
 * Every entry in a prefetch asset group, deduplicated and in manifest order.
 *
 * Each carries the group it came from, because how a URL is fetched and where it is
 * stored depend on it: an asset is fetched plainly and lives in the shared precache, a
 * page is fetched the way the runtime would and lives in the visitor pages cache.
 */
function prefetchEntries(manifest) {
    const entries = [];
    const seen = new Set();

    for (const group of manifest.assetGroups || []) {
        if (group.installMode === 'lazy') {
            continue;
        }

        for (const url of group.urls || []) {
            if (seen.has(url)) {
                continue;
            }

            seen.add(url);
            entries.push({
                url,
                kind: group.kind === 'page' ? 'page' : 'asset',
                credentials: group.credentials,
            });
        }
    }

    return entries;
}

async function fetchManifest() {
    try {
        const response = await fetch(MANIFEST_URL, { cache: 'reload', credentials: 'same-origin' });

        if (!response.ok) {
            return null;
        }

        const manifest = await response.json();

        // A manifest that disagrees with the worker rendered from it means a deploy
        // landed between the two requests. The fetched copy is the newer one and is what
        // gets installed; the mismatch resolves itself when the browser picks up the
        // worker built from it, which will be byte-different and so install in turn.
        if (MANIFEST_HASH && manifest.hash !== MANIFEST_HASH) {
            console.info('pwax sw: a deploy landed mid-install, installing the newer manifest');
        }

        return manifest;
    } catch {
        return null;
    }
}

/* --------------------------------------------------------------------- routing ----- */

/**
 * Answer a request, and never reject.
 *
 * `respondWith` given a rejected promise fails the request *and* reports an unhandled
 * rejection — `Uncaught (in promise) TypeError: Failed to fetch`, attributed to the
 * worker rather than to whatever asked for the URL. Any SPA produces those routinely:
 * navigating away aborts the in-flight page request, and a dev server that restarts
 * refuses connections for a moment.
 *
 * `Response.error()` is the same outcome without the noise. The page's own `fetch` still
 * rejects with a TypeError at its own call site, which is what the runtime already reads
 * as "no connection" — the worker just stops claiming the failure as its own.
 */
async function route(event) {
    try {
        return await serve(event);
    } catch {
        // A navigation still gets a page. Nothing above should be able to throw on that
        // path, but "should" is not a guarantee worth handing the browser's own error
        // screen to someone who installed this to work offline.
        return event.request.mode === 'navigate' ? offlineDocument() : Response.error();
    }
}

async function serve(event) {
    const request = event.request;
    const manifest = await state();

    // First, and before any cache is consulted. A navigation is a request for a document,
    // and the only document it may be answered with is the shell — never a page payload,
    // whatever else happens to be stored under the same URL.
    if (request.mode === 'navigate') {
        return navigate(event, manifest);
    }

    const url = new URL(request.url);
    const key = url.pathname + url.search;

    // Before the precache, because a page URL may also sit under an asset prefix, and the
    // page path is the more specific answer. The header test means only the runtime's own
    // request takes this branch.
    const group = pageGroupFor(manifest, key, request);

    if (group) {
        return page(event, manifest, group);
    }

    const precache = await precacheFor(manifest);

    // Precached entries are content-addressed by the manifest hash, so a hit is by
    // definition the right version and there is nothing to revalidate.
    //
    // `ignoreVary` is safe here and nowhere it is not used: every entry was put here by
    // `install()`, one per URL, so there is only ever one representation to choose from.
    // A Vary check could only turn the correct hit into a spurious miss.
    if (precache) {
        const hit = await precache.match(request, { ignoreVary: true });

        if (hit) {
            return hit;
        }
    }

    const lazy = lazyGroupFor(manifest, key);

    if (lazy) {
        return lazyAsset(event, manifest);
    }

    const data = dataGroupFor(manifest, key);

    if (data) {
        return dataResponse(event, manifest, data);
    }

    const ours = (manifest.assetPrefixes || []).some((prefix) => url.pathname.startsWith(prefix));

    if (ours) {
        return staleWhileRevalidate(request, manifest);
    }

    // Everything else: a URL this application never declared. `network-only` is the
    // default, and it stores nothing — a one-off download, a CSV export, a file under
    // /storage is not part of the app and does not belong in its cache. `runtime_strategy`
    // opts back into keeping them.
    if (manifest.strategy === 'stale-while-revalidate') {
        return staleWhileRevalidate(request, manifest);
    }

    if (manifest.strategy === 'network-first') {
        return networkFirst(request, manifest);
    }

    return fetch(request);
}

/**
 * The page group a request belongs to, if it is a page request at all.
 *
 * The header is what distinguishes "the runtime asking for /about's payload" from "a
 * link to /about". Without it a hit in the pages cache could answer a document request
 * with JSON, and the browser would download it.
 */
function pageGroupFor(manifest, key, request) {
    if (request.headers.get('X-Pwax-Component') !== 'true') {
        return null;
    }

    for (const group of manifest.assetGroups || []) {
        if (group.kind !== 'page') {
            continue;
        }

        if ((group.urls || []).includes(key) || matchesAny(group.patterns, key)) {
            return group;
        }
    }

    // A page the runtime asked for that no group claims is still a page, and caching it as
    // one is what makes "everywhere you have been works offline" true rather than
    // "everywhere you listed in config". `runtime: false` turns this off.
    return manifest.pageRuntime === false
        ? null
        : manifest.pageDefaults || { strategy: 'network-first' };
}

/**
 * A page payload: from the network when there is one, from the cache when there is not.
 *
 * Caches are shared across visitors. Whatever the server returns for a URL is stored and
 * served to the next visitor. A page that genuinely must not reach disk uses
 * `->offline(false)`; the server still says so on the response, and the worker respects it.
 */
async function page(event, manifest, group) {
    const request = event.request;

    if (group.strategy === 'cache-first') {
        const hit = await storedPage(request, manifest);

        if (hit) {
            return hit;
        }
    }

    try {
        const response = await withTimeout(fetch(request), group.timeout);

        if (isJson(response) && storablePage(response)) {
            const url = new URL(request.url);
            const copy = response.clone();

            // Handed to the event, not awaited. The page is on its way to the runtime the
            // moment the network answered; opening a cache, writing to it and then walking
            // its keys to trim are three storage round-trips the visitor was being made to
            // wait through, on every single navigation, for work whose only purpose is the
            // *next* visit. `waitUntil` keeps the worker alive until it finishes without
            // holding the response behind it.
            event.waitUntil(
                caches.open(pagesName(manifest)).then(async (cache) => {
                    await cache.put(pageKey(url.pathname + url.search), copy);
                    await trim(cache, pagesName(manifest), group.maxEntries || manifest.maxEntries);
                })
            );
        }

        // A reply is not the same as an answer. Falling back only when `fetch` throws
        // covers the network being gone and nothing else — a proxy between here and the
        // origin, or an application mid-deploy, resolves.
        if (unreachable(response)) {
            const hit = await storedPage(request, manifest);

            if (hit) {
                warnStale(request.url, response.status);

                return hit;
            }
        }

        return response;
    } catch (error) {
        const hit = await storedPage(request, manifest);

        if (hit) {
            return hit;
        }

        throw error;
    }
}

/**
 * This page as it was last stored.
 *
 * The same cache for every visitor. Caches are shared, so the page the server returned
 * last is what the next visitor gets.
 */
async function storedPage(request, manifest) {
    // `has` before `open`: `caches.open` *creates*, and a read that brings a cache into
    // existence leaves an empty one behind for every visitor who merely looked at a page.
    // Those empty caches are litter.
    //
    // Match by the request the runtime actually sends, with its `Vary` headers. A stored
    // entry with no headers would not be matched by the runtime's request, and matching
    // with `ignoreVary` would let a bare URL hit an entry that should only answer the
    // component-bearing request.
    const name = pagesName(manifest);
    const cache = await openIfPresent(name);
    const hit = cache && (await cache.match(request));

    if (hit) {
        return hit;
    }

    return undefined;
}

/** A cache, but only if it already exists. `caches.open` creates; a read must not. */
async function openIfPresent(name) {
    return (await caches.has(name)) ? caches.open(name) : null;
}

/** Reject a promise that takes too long, so a dead connection is not an indefinite wait. */
function withTimeout(promise, ms) {
    if (!ms || ms <= 0) {
        return promise;
    }

    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('pwax sw: timed out')), ms);

        promise.then(
            (value) => {
                clearTimeout(timer);
                resolve(value);
            },
            (error) => {
                clearTimeout(timer);
                reject(error);
            }
        );
    });
}

function matchesAny(patterns, key) {
    return compiled(patterns).some((rule) => rule.pattern.test(key));
}

/** A lazy asset group claiming this path, if any. */
function lazyGroupFor(manifest, key) {
    for (const group of manifest.assetGroups || []) {
        if (group.installMode !== 'lazy' || group.kind === 'page') {
            continue;
        }

        if ((group.urls || []).includes(key) || matchesAny(group.patterns, key)) {
            return group;
        }
    }

    return null;
}

/**
 * A lazy group member: fetched on first use, then kept.
 *
 * Its own cache, and deliberately not the precache. A lazy asset is still part of the
 * application — it was declared in the manifest — so it must not be evicted by ordinary
 * browsing, which is what the runtime cache's entry cap would eventually do to it. But the
 * precache is named for the build, `install()` never puts a lazy entry in one, and
 * `activate()` deletes the previous build's precache outright — so keeping them there
 * meant every deploy silently threw away every image, font and media file the visitor had
 * accumulated, and re-downloaded each on next use. That is the exact churn the delta
 * install exists to avoid, and it was happening to the largest files in the application.
 *
 * `${PREFIX}-lazy` survives deploys for the same reason `runtimeName()` does. Entries
 * whose content actually changed are dropped in `activate()`, by hash.
 */
async function lazyAsset(event, manifest) {
    const request = event.request;
    const cache = await caches.open(lazyName());
    const hit = await cache.match(request, { ignoreVary: true });

    if (hit) {
        return hit;
    }

    const response = await fetch(request);

    if (cacheable(response) && !tooLarge(response, manifest)) {
        const copy = response.clone();

        event.waitUntil(cache.put(request, copy));
    }

    return response;
}

/** The first data group claiming this path, if any. */
function dataGroupFor(manifest, key) {
    for (const group of manifest.dataGroups || []) {
        if (matchesAny(group.patterns, key)) {
            return group;
        }
    }

    return null;
}

/**
 * An API response, cached under its data group's rules.
 *
 * `freshness` goes to the network with a deadline and falls back to what is stored;
 * `performance` serves what is stored while it is young enough, and only then asks the
 * network. Age is tracked in a sidecar entry rather than by rewriting the response,
 * because rebuilding a response to add a header would mean reading its body first, which
 * forfeits streaming for every request that goes through here.
 */
async function dataResponse(event, manifest, group) {
    const request = event.request;
    const config = group.cacheConfig || {};
    const name = dataName(group);
    const url = new URL(request.url);
    const key = url.pathname + url.search;

    // Opened lazily, and only for a read that has something to read. Opening at the top
    // created this cache for every request that passed through, so a device that had never
    // successfully stored an API response still accumulated an empty cache per group.
    const stored = async () => {
        const cache = await openIfPresent(name);
        const hit = cache && (await cache.match(request));

        return hit && (await isYoungEnough(cache, key, config.maxAge)) ? hit : null;
    };

    const fresh = async () => {
        const response = await withTimeout(fetch(request), config.timeout);

        if (cacheable(response)) {
            const copy = response.clone();

            // Three storage round-trips, none of which the caller needs to have finished.
            // The put and the stamp do not depend on each other, so they go together;
            // trimming waits for both, because it counts what they wrote.
            event.waitUntil(
                caches.open(name).then(async (cache) => {
                    await Promise.all([cache.put(request, copy), stamp(cache, key)]);
                    await trimData(cache, name, config.maxSize);
                })
            );
        }

        // Same rule as a page: an origin that cannot be reached is not an answer, and a
        // stored copy within its `max_age` beats handing the application a 502 to render.
        // A 500 goes through — the API ran and failed, and that is worth knowing.
        if (unreachable(response)) {
            const hit = await stored();

            if (hit) {
                warnStale(request.url, response.status);

                return hit;
            }
        }

        return response;
    };

    if (config.strategy === 'cache-first') {
        const hit = await stored();

        if (hit) {
            return hit;
        }
    }

    try {
        return await fresh();
    } catch (error) {
        const hit = await stored();

        if (hit) {
            return hit;
        }

        throw error;
    }
}

/** Sidecar entries record when a data response was stored. */
function stampKey(key) {
    return `/__pwax__/sw-age/${encodeURIComponent(key)}`;
}

async function stamp(cache, key) {
    await cache.put(stampKey(key), new Response(JSON.stringify({ t: Date.now() })));
}

async function isYoungEnough(cache, key, maxAge) {
    if (!maxAge) {
        return true;
    }

    try {
        const stored = await cache.match(stampKey(key));

        if (!stored) {
            return false;
        }

        const { t } = await stored.json();

        return Date.now() - t < maxAge * 1000;
    } catch {
        return false;
    }
}

/** Bound a data cache, counting only real entries and dropping their stamps with them. */
async function trimData(cache, name, maxSize) {
    if (!maxSize || maxSize <= 0) {
        return;
    }

    const believed = counts.get(name);

    if (believed !== undefined) {
        counts.set(name, believed + 1);

        if (believed + 1 <= maxSize) {
            return;
        }
    }

    const keys = (await cache.keys()).filter(
        (key) => !new URL(key.url).pathname.startsWith('/__pwax__/sw-age/')
    );

    counts.set(name, keys.length);

    if (keys.length <= maxSize) {
        return;
    }

    // One URL parse per key rather than two, and the deletes together rather than in turn
    // — an over-full cache would otherwise spend two serial round-trips per evicted entry.
    await Promise.all(
        keys.slice(0, keys.length - maxSize).flatMap((key) => {
            const url = new URL(key.url);

            return [cache.delete(key), cache.delete(stampKey(url.pathname + url.search))];
        })
    );

    counts.set(name, maxSize);
}

/**
 * Navigations go to the network, and any response that is safe to keep is kept.
 *
 * A page has two representations. The runtime asks for the payload and gets JSON; a
 * reload, a bookmark, a link from outside the app is a navigation and gets HTML with the
 * component inlined. Only the first was ever stored after install, so a route the build
 * did not precache — a dynamic one, or anything route discovery could not reach — had no
 * document at all, and reloading it offline fell all the way back to the shell.
 *
 * Caches are shared across visitors; whatever HTML the server returns for a URL is
 * stored, and the next visitor that asks gets the same answer.
 */
async function navigate(event, manifest) {
    const path = new URL(event.request.url).pathname;
    const preload = manifest.navigationPreload !== false ? event.preloadResponse : null;

    // A path the application does not own goes straight to the network, untouched. This
    // is the escape hatch that keeps Horizon, Telescope, Nova, a Filament panel or a PDF
    // under /storage working on an origin where the app-shell strategy is switched on —
    // without it, every one of them would be answered with the SPA shell.
    //
    // The preload is used rather than ignored. The browser has already sent this request;
    // calling `fetch` instead would send it a second time and throw the first away, so
    // every navigation the worker declines to handle cost the server two.
    if (!matchesNavigationUrls(manifest, path)) {
        return (await settled(preload)) || fetch(event.request);
    }

    if (manifest.navigationStrategy === 'cache-first') {
        const cached = (await storedDocument(manifest, path)) || (await shellDocument(manifest));

        if (cached) {
            // Answered from disk, so the preload is not wanted — but it has to be settled
            // all the same. Left dangling it logs "the navigation preload request was
            // cancelled before preloadResponse settled" in the console of every
            // application using this strategy, which reads like a bug and is not one.
            await settled(preload);

            return cached;
        }
    }

    try {
        const response = (await settled(preload)) || (await fetch(event.request));

        // Off the critical path. The document is already on its way to the browser while
        // this writes; a navigation is the one response where waiting on a cache write
        // would be paid for in first paint.
        event.waitUntil(rememberDocument(response, manifest, path));

        // The same rule a page payload follows. An origin that is deploying, or behind a
        // proxy that cannot reach it, would otherwise replace an application installed on
        // the device with whatever error page came back — while a document for this exact
        // route sat in the precache. A 500 is not that: the application ran and threw, and
        // that page is the thing whoever deployed it needs to see.
        if (unreachable(response)) {
            const stored =
                (await storedDocument(manifest, path)) || (await shellDocument(manifest));

            if (stored) {
                warnStale(event.request.url, response.status);

                return stored;
            }
        }

        return response;
    } catch {
        // This page's own HTML first, and the shell only if there is none. The shell is a
        // correct answer but a worse one: it paints a spinner and waits for the runtime to
        // fetch a payload, where the document already has the component inlined.
        return (
            (await storedDocument(manifest, path)) ||
            (await shellDocument(manifest)) ||
            offlineDocument()
        );
    }
}

/**
 * Keep a navigation's HTML, when it is safe to keep.
 *
 * Caches are shared across visitors. A response is stored as long as it is `ok`, HTML,
 * and not a followed redirect (a route behind `auth` answers a navigation by redirecting
 * to the login screen, and `fetch` follows it silently — so without this the login page
 * would be stored as that route's document and served under its URL forever).
 */
async function rememberDocument(response, manifest, path) {
    // The same switch that governs the payload. `pages.runtime => false` is documented as
    // the way to keep page content off disk entirely, and a document is page content —
    // more of it than the payload, since the markup is rendered rather than described.
    if (manifest.pageRuntime === false) {
        return;
    }

    if (
        !response ||
        !response.ok ||
        response.redirected ||
        !isHtml(response) ||
        !storablePage(response)
    ) {
        return;
    }

    // Cloned before the first `await`, while the body is still untouched. The response
    // itself is being read by the browser at the same time, and a clone taken after it has
    // started is a locked stream.
    const copy = response.clone();

    try {
        const cache = await caches.open(documentsName(manifest));
        const defaults = manifest.pageDefaults || {};

        await cache.put(path, copy);

        // Bounded by `pages.max_entries`, not the runtime cache's. A document is a page,
        // and the setting that says how many pages to keep should govern both of its
        // halves.
        await trim(cache, documentsName(manifest), defaults.maxEntries || manifest.maxEntries);
    } catch {
        // A full disk is the likely one, and this is the least important thing on it: the
        // payload is already stored and is what offline correctness rests on. A document
        // that could not be written costs a spinner. Swallowed rather than left to reject
        // inside `waitUntil`, where it would be reported as the worker failing.
    }
}

/**
 * The stored HTML for a path: what the device has seen, then what the build shipped.
 *
 * Caches are shared across visitors. Whatever HTML the server returned for the URL is
 * what comes back.
 */
async function storedDocument(manifest, path) {
    const name = documentsName(manifest);

    // `has` before `open`: a read must not bring a cache into existence.
    if (await caches.has(name)) {
        const mine = await (await caches.open(name)).match(path, { ignoreVary: true });

        if (mine) {
            return mine;
        }
    }

    return pageDocument(manifest, path);
}

function isHtml(response) {
    return (response.headers.get('Content-Type') || '').includes('html');
}

/**
 * The precached HTML for a specific path, if this build stored one.
 *
 * Looked up in the precache by exact URL. The second half of `storedDocument()`, and the
 * older half: this is what the *build* installed, where the documents cache holds what the
 * device has since visited. A route discovery could not read has neither until somebody
 * opens it, and until then the shell answers.
 */
async function pageDocument(manifest, path) {
    try {
        const precache = await precacheFor(manifest);

        return precache ? (await precache.match(path, { ignoreVary: true })) || null : null;
    } catch {
        return null;
    }
}

/**
 * The precached document a navigation falls back to.
 *
 * Looked up by URL in the cache it was put in. `caches.match()` would iterate every
 * cache the origin owns to find an entry under this URL; the shell is in exactly one of
 * them, so the iteration is wasted work. Opening the known cache directly is one cache
 * call instead of every one of them.
 */
async function shellDocument(manifest) {
    for (const url of [manifest.offlineUrl, manifest.shellUrl]) {
        if (!url) {
            continue;
        }

        const cache = await openIfPresent(precacheName(manifest));

        if (cache) {
            const hit = await cache.match(url, { ignoreVary: true });

            if (hit) {
                return hit;
            }
        }
    }

    return null;
}

/**
 * Which navigations belong to the application.
 *
 * A list of globs, already compiled to regexes by the server. A leading `!` excludes.
 * With no list configured every navigation is ours, which is the behaviour that existed
 * before this was configurable.
 */
function matchesNavigationUrls(manifest, path) {
    const rules = compiled(manifest.navigationUrls);

    if (!rules.length) {
        return true;
    }

    let included = false;

    for (const rule of rules) {
        if (!rule.pattern.test(path)) {
            continue;
        }

        if (rule.negated) {
            return false;
        }

        included = true;
    }

    return included;
}

/**
 * Compiled rule lists, so a request does not pay to build the same regexes again.
 *
 * Weak, and keyed on the array itself rather than its contents. The manifest is read once
 * and memoised, so every request sees the same array instances; a deploy brings new ones,
 * misses, and lets the old entries go without anything having to remember to evict them.
 */
const compiledRules = new WeakMap();

/**
 * A rule list as compiled regexes, built once.
 *
 * `new RegExp()` per rule per request is small and entirely avoidable. These lists come
 * from the manifest and do not change between requests, and they are consulted on the
 * paths that decide whether a navigation is answered at all and which group claims a URL
 * — which is to say, on everything the worker handles.
 */
function compiled(rules) {
    if (!rules || !rules.length) {
        return [];
    }

    let entries = compiledRules.get(rules);

    if (!entries) {
        entries = [];

        for (const rule of rules) {
            const negated = rule.startsWith('!');

            try {
                entries.push({ negated, pattern: new RegExp(negated ? rule.slice(1) : rule) });
            } catch {
                // One unusable pattern is not a reason to stop answering navigations.
                // Uncaught, this throws out of `matchesNavigationUrls`, and every
                // navigation in the application is answered with the offline page — an
                // outage produced by a rule that was only ever meant to narrow a match.
                console.warn('pwax sw: ignoring an asset pattern that will not compile', rule);
            }
        }

        compiledRules.set(rules, entries);
    }

    return entries;
}

/**
 * A navigation preload, resolved without letting its failure become the page's.
 *
 * The preload rejects for the ordinary reason any request does — there is no network —
 * and on the app-shell path that is not an error at all, because the answer is already on
 * disk. Returning null lets the caller decide.
 */
async function settled(preload) {
    if (!preload) {
        return null;
    }

    try {
        return (await preload) || null;
    } catch {
        return null;
    }
}

function offlineDocument() {
    return new Response(OFFLINE_HTML, {
        status: 503,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            // A navigation the worker answered from disk should not give the document
            // any permission the application did not ask for. `style-src 'unsafe-inline'`
            // is needed because the page's only stylesheet is the `<style>` block above.
            'Content-Security-Policy':
                "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options': 'nosniff',
            'Referrer-Policy': 'no-referrer',
            'X-Robots-Tag': 'noindex',
        },
    });
}

async function networkFirst(request, manifest) {
    try {
        const response = await fetch(request);
        await put(request, response.clone(), manifest);

        // An unreachable origin is not an answer here either. `put()` has already refused
        // to store it — `cacheable()` rejects anything that is not `ok` — so what is looked
        // up is the last good copy, never the error that just arrived.
        if (unreachable(response)) {
            const cached = await matchScoped(request, manifest);

            if (cached) {
                warnStale(request.url, response.status);

                return cached;
            }
        }

        return response;
    } catch (error) {
        const cached = await matchScoped(request, manifest);

        if (cached) {
            return cached;
        }

        throw error;
    }
}

async function staleWhileRevalidate(request, manifest) {
    const cached = await matchScoped(request, manifest);

    const network = fetch(request)
        .then(async (response) => {
            await put(request, response.clone(), manifest);
            return response;
        })
        // `?? Response.error()` matters: with nothing cached and the network down this
        // resolved to `undefined`, and `respondWith` given a non-Response fails the
        // request with "the promise was resolved with an object that is not a Response"
        // — a confusing error in place of an ordinary offline one.
        .catch(() => cached ?? Response.error());

    return cached || network;
}

/* ------------------------------------------------------------------ cache reads ----- */

/**
 * Everything this origin is allowed to be answered from.
 *
 * Caches are shared across visitors: there is one runtime cache and one precache, both
 * keyed on the build, both read by anybody.
 */
async function matchScoped(request, manifest) {
    const name = runtimeName();

    // `has` before `open`, because `open` creates. A read has no business bringing a
    // cache into existence: every visitor who merely looked at a page whose response
    // could not be stored would leave an empty cache behind.
    if (await caches.has(name)) {
        const hit = await (await caches.open(name)).match(request);

        if (hit) {
            return hit;
        }
    }

    const precache = await precacheFor(manifest);

    return precache ? precache.match(request, { ignoreVary: true }) : undefined;
}

/* ----------------------------------------------------------------- cache writes ----- */

async function put(request, response, manifest) {
    if (!cacheable(response) || tooLarge(response, manifest)) {
        return;
    }

    const cache = await caches.open(runtimeName());
    await cache.put(request, response);
    await trim(cache, runtimeName(), manifest.maxEntries);
}

/**
 * Is this response too big to be worth a place in the runtime cache?
 *
 * The entry cap counts entries, and sixty JSON payloads and sixty videos are not the same
 * amount of somebody's disk. One large response can crowd out everything the cap was meant
 * to protect, or push the origin over its quota and have the browser evict the precache —
 * which takes the application's offline capability with it.
 *
 * Only `Content-Length` is consulted. Reading the body to measure it would mean buffering
 * the very responses this exists to avoid buffering, so a streamed response with no length
 * is allowed through: the cap on entries still bounds it, and guessing is worse.
 */
function tooLarge(response, manifest) {
    const limit = manifest.maxEntryBytes || 0;

    if (limit <= 0) {
        return false;
    }

    const declared = Number(response.headers.get('Content-Length'));

    return Number.isFinite(declared) && declared > limit;
}

/**
 * Is this response one we are allowed to keep?
 *
 * An opaque response has status 0 and an unreadable body; caching one wastes quota and
 * can serve an error page forever. Partial content is equally unsafe to store. And
 * `no-store` is the server saying, in the only way it has, that this body belongs to one
 * moment — component modules and page payloads carrying user data say exactly that, and
 * honouring it is what keeps them off disk.
 *
 * This governs assets. Page payloads take the other gate, `storablePage()`, which is
 * deliberately more permissive and explains itself there — do not merge the two.
 */
function cacheable(response) {
    if (!response || !response.ok || response.status === 206 || response.type === 'opaque') {
        return false;
    }

    // `->offline(false)` on a page, for content that must not reach disk at all — a
    // one-time code, a recovery key, a record on a shared terminal. Checked before
    // `Cache-Control` because it is the stronger statement of the two and admits no
    // exception anywhere in this file.
    if (response.headers.get('X-Pwax-Cache') === 'none') {
        return false;
    }

    return !/(^|,)\s*no-store\s*(,|$)/i.test(response.headers.get('Cache-Control') || '');
}

/**
 * May this page payload be stored?
 *
 * Caches are shared across visitors. A page that the server rendered for whoever asked is
 * served to the next visitor; the assumption is that pages are static (so the next render
 * would be identical), or that a small staleness is acceptable. A page that genuinely must
 * not reach disk at all uses `->offline(false)`.
 */
function storablePage(response) {
    if (!response || !response.ok || response.status === 206 || response.type === 'opaque') {
        return false;
    }

    return response.headers.get('X-Pwax-Cache') !== 'none';
}

/**
 * Keep the runtime cache bounded.
 *
 * Only the runtime cache. Precached entries live in their own cache and are never
 * trimmed, so ordinary browsing can no longer evict the app shell or the framework and
 * quietly take the application offline-capability away.
 */
/**
 * Entries believed to be in each bounded cache, so `keys()` is not walked on every write.
 *
 * `cache.keys()` materialises a Request per entry. Called on every put — which is what
 * this did — a sixty-entry page cache built sixty Request objects per navigation to
 * discover, almost always, that nothing needed deleting. The count is advisory: it is
 * seeded from one real `keys()` call, and a worker restart simply costs another. Nothing
 * is ever deleted on its word alone; the real check still runs before any eviction.
 */
const counts = new Map();

async function trim(cache, name, maxEntries) {
    const max = maxEntries || 0;

    if (max <= 0) {
        return;
    }

    const believed = counts.get(name);

    if (believed !== undefined) {
        counts.set(name, believed + 1);

        if (believed + 1 <= max) {
            return;
        }
    }

    const keys = await cache.keys();

    counts.set(name, keys.length);

    if (keys.length <= max) {
        return;
    }

    await Promise.all(keys.slice(0, keys.length - max).map((key) => cache.delete(key)));

    counts.set(name, max);
}

async function clearCaches() {
    const keys = await caches.keys();

    await Promise.all(
        keys.filter((key) => key.startsWith(`${PREFIX}-`)).map((key) => caches.delete(key))
    );

    statePromise = null;
    counts.clear();
}

/* ------------------------------------------------------------------------ state ----- */

/**
 * Cache names are derived from the manifest hash alone.
 *
 * No version, no build id, no install bucket — the hash changes whenever the build does,
 * which is the only signal that ever needs to rename a cache. The hash is in the manifest
 * the worker shipped with, so the name is determined by the worker, not by guesswork.
 */
function precacheName(manifest) {
    return `${PREFIX}-precache-${manifest.hash}`;
}

/**
 * Where page payloads live, whoever fetched them.
 *
 * One cache per build (keyed by the manifest hash), shared across all visitors. Whatever
 * the server returned for a URL is what the next visitor gets; there is no per-person
 * partition, no per-person wiping, and no separate install bucket — page payloads written
 * by `install()` and page payloads written by the runtime share the same cache.
 */
function pagesName(manifest) {
    return `${PREFIX}-pages-${manifest.hash}`;
}

/**
 * Where navigation HTML lives.
 *
 * One cache per build, shared across all visitors. Whatever document the server returns
 * for a URL is stored, and the next visitor that asks gets the same answer.
 */
function documentsName(manifest) {
    return `${PREFIX}-documents-${manifest.hash}`;
}

/**
 * Where runtime-cached assets live.
 *
 * Not keyed by the build. These are URLs the application declared rather than ones the
 * build compiled, and re-downloading them on every deploy is exactly the churn this cache
 * exists to avoid. Not keyed by anything else, either — the device has one runtime cache,
 * shared by whoever is using it.
 */
function runtimeName() {
    return `${PREFIX}-runtime`;
}

/**
 * Where lazy asset groups live.
 *
 * Not keyed by the build, for the reason set out on `lazyAsset()`: these are files the
 * application declared and a visitor fetched, and a deploy that changed a component has
 * no business re-downloading every image on the device. Entries whose own hash changed
 * are dropped in `activate()`.
 */
function lazyName() {
    return `${PREFIX}-lazy`;
}

/**
 * Where requests queued while offline wait.
 *
 * Not keyed by the build. Work queued before a deploy still has to be sent after it, and
 * discarding it because a component changed would lose a visitor's writes.
 */
function syncName() {
    return `${PREFIX}-sync`;
}

/**
 * Where a data group's responses live.
 *
 * The version is part of the name, which is the whole point of `data_groups[].version` —
 * bumping it is how you say "discard what is stored for this group". Before, the version
 * reached the manifest and stopped there: it changed the manifest hash, so bumping it
 * re-precached the entire application for every client, and left the one cache it was
 * meant to empty exactly as it was.
 */
function dataName(group) {
    return `${PREFIX}-data-${group.name}-v${group.version || 1}`;
}

async function precacheFor(manifest) {
    const name = precacheName(manifest);

    return (await caches.has(name)) ? caches.open(name) : null;
}

/** Precaches from earlier manifests, newest first, as candidates to copy from. */
async function previousPrecaches(current) {
    const keys = await caches.keys();
    const names = keys.filter((key) => key.startsWith(`${PREFIX}-precache-`) && key !== current);

    return Promise.all(names.map((name) => caches.open(name)));
}

/**
 * The hashes the previous caches were built from, so entries can be reused by content.
 */
async function inheritedHashes(previous) {
    const hashes = new Map();
    const holder = new Map();

    for (const cache of previous) {
        try {
            const stored = await cache.match(MANIFEST_URL);

            if (!stored) {
                continue;
            }

            const manifest = await stored.json();

            for (const [url, hash] of Object.entries(manifest.hashTable || {})) {
                hashes.set(url, hash);

                // Which cache to copy that URL out of, decided once here instead of by
                // `store()` probing every previous cache in turn for every unchanged
                // asset. With three builds retained that was up to three serial storage
                // round-trips per file, inside a six-way limiter, on the one path a deploy
                // is supposed to make cheap.
                //
                // Overwritten rather than kept: `previousPrecaches` lists oldest first, so
                // the last writer is the newest build holding this URL — the same build
                // the hash above now comes from. A miss against it is not a problem;
                // `store()` falls through and fetches.
                holder.set(url, cache);
            }
        } catch {
            // A cache without a readable manifest simply contributes nothing.
        }
    }

    return { hashes, holder };
}

/**
 * The manifest this worker is currently serving.
 *
 * Persisted rather than held in memory: a service worker is terminated between events
 * whenever the browser feels like it, and a fetch handler that had to rediscover which
 * cache was current would either guess or go to the network.
 */
function state() {
    statePromise ??= (async () => installed || (await readManifest(STATE_KEY)) || CONFIG)();

    return statePromise;
}

async function readManifest(key) {
    try {
        const cache = await caches.open(STATE_CACHE);
        const stored = await cache.match(key);

        return stored ? await stored.json() : null;
    } catch {
        return null;
    }
}

async function writeManifest(key, manifest) {
    try {
        const cache = await caches.open(STATE_CACHE);

        await cache.put(
            key,
            new Response(JSON.stringify(manifest), {
                headers: { 'Content-Type': 'application/json; charset=utf-8' },
            })
        );
    } catch {
        // Losing the pointer costs a fall back to the inlined config, nothing more.
    }
}

async function deleteManifest(key) {
    try {
        const cache = await caches.open(STATE_CACHE);
        await cache.delete(key);
    } catch {
        // A stale pending pointer is only ever read when there is no active one.
    }
}

/**
 * Answer a message on the port it arrived with, falling back to the client itself.
 *
 * A `MessageChannel` port is how the page waits for a reply; without it the page has to
 * listen on `navigator.serviceWorker` and correlate responses by hand.
 */
function reply(event, message) {
    const port = event.ports && event.ports[0];

    if (port) {
        port.postMessage(message);

        return;
    }

    if (event.source) {
        event.source.postMessage(message);
    }
}
