{{--
    Default Pwax service worker.

    Driven by the asset manifest at `sw.json`, in the same way Angular's worker is driven
    by `ngsw.json`: the server enumerates every URL the application is made of with a
    content hash, and this installs the lot in one pass. A visitor who has loaded one page
    can go offline and still reach every route and every component.

    Publish and edit with:
        php artisan vendor:publish --tag=pwax-service-worker

    `$manifest` is the built asset manifest. Its hash is embedded below so that a change
    anywhere in the application changes this file — which is the only thing that makes a
    browser treat the worker as new and install it.
--}}
@php
    $manifest ??= [];
    $prefix = (string) ($manifest['cachePrefix'] ?? config('pwax.service_worker.cache_name', 'pwax'));
    $manifestUrl = '/' . ltrim((string) config('pwax.service_worker.asset_manifest.path', '/sw.json'), '/');

    // Assembled here rather than inline below, because `@json` splits its argument on
    // commas — it reads them as (value, flags, depth) — so an array literal written in
    // the directive is shredded into a syntax error.
    $swConfig = [
        'hash' => (string) ($manifest['hash'] ?? ''),
        'version' => (string) ($manifest['version'] ?? 'v1'),
        'strategy' => (string) ($manifest['strategy'] ?? 'network-first'),
        'maxEntries' => (int) ($manifest['maxEntries'] ?? 60),
        'navigationPreload' => (bool) ($manifest['navigationPreload'] ?? true),
        'shellUrl' => $manifest['shellUrl'] ?? null,
        'offlineUrl' => $manifest['offlineUrl'] ?? null,
        'assetPrefixes' => array_values((array) ($manifest['assetPrefixes'] ?? [])),
    ];
@endphp
/*!
 * pwax service worker
 * manifest: {{ $manifest['hash'] ?? 'unknown' }}
 * version:  {{ $manifest['version'] ?? 'v1' }}
 */
const MANIFEST_URL = @json($manifestUrl, JSON_UNESCAPED_SLASHES);
const MANIFEST_HASH = @json((string) ($manifest['hash'] ?? ''));
const PREFIX = @json($prefix);
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
 * The routing-relevant part of the manifest, rendered into this file.
 *
 * Deliberately not the whole thing: the asset table belongs in `sw.json`, which is
 * fetched at install and stored alongside the cache it built. What is inlined here is
 * only what the fetch handler needs to answer a request — which cache to look in, what to
 * serve when a navigation fails — so that a worker the browser revived after terminating
 * it can respond immediately without a network round trip.
 */
const CONFIG = @json($swConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

const OFFLINE_HTML =
    '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width, initial-scale=1">' +
    '<title>Offline</title></head><body style="font-family:system-ui,sans-serif;padding:2rem">' +
    '<h1>You are offline</h1><p>This page has not been stored for offline use.</p>' +
    '</body></html>';

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
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Only GET is cacheable, and only our own origin is ours to cache. Range requests are
    // skipped because a partial response cached as a whole one corrupts media playback.
    if (request.method !== 'GET' || request.headers.has('range')) {
        return;
    }

    if (new URL(request.url).origin !== self.location.origin) {
        return;
    }

    event.respondWith(route(event));
});

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
    const cache = await caches.open(cacheName);
    const previous = await previousPrecaches(cacheName);
    const inherited = await inheritedHashes(previous);

    const entries = prefetchUrls(manifest);
    const critical = new Set(manifest.critical || []);
    const crossOrigin = new Set(manifest.crossOrigin || []);

    // Individually, not with `cache.addAll`. `addAll` is atomic: one 404 anywhere in the
    // list rejects the whole thing, and the usual `.catch(console.warn)` around it then
    // activates a worker with an entirely empty cache while reporting success.
    const results = await Promise.allSettled(
        entries.map((url) =>
            store(cache, url, {
                hash: (manifest.hashTable || {})[url],
                inherited,
                previous,
                crossOrigin: crossOrigin.has(url),
            })
        )
    );

    const failures = entries.filter((_, i) => results[i].status === 'rejected');
    const fatal = failures.filter((url) => critical.has(url));

    if (failures.length) {
        console.warn(`pwax sw: ${failures.length} of ${entries.length} assets could not be precached`, failures);
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

    installed = manifest;

    await writeManifest(PENDING_KEY, manifest);
}

async function activate() {
    const manifest = installed || (await readManifest(PENDING_KEY)) || (await readManifest(STATE_KEY)) || CONFIG;
    const keep = new Set([precacheName(manifest), runtimeName(manifest), STATE_CACHE]);

    // Promoted before the sweep, so a worker terminated midway through cannot come back
    // and mistake the cache it is activating for a stale one.
    await writeManifest(STATE_KEY, manifest);
    statePromise = Promise.resolve(manifest);

    // Only our own caches. Another worker, or another library, may own caches on this
    // origin and deleting those would be someone else's outage.
    const keys = await caches.keys();
    await Promise.all(
        keys.filter((key) => key.startsWith(`${PREFIX}-`) && !keep.has(key)).map((key) => caches.delete(key))
    );

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
async function store(cache, url, { hash, inherited, previous, crossOrigin }) {
    if (hash && inherited.get(url) === hash) {
        for (const old of previous) {
            const copy = await old.match(url);

            if (copy) {
                await cache.put(url, copy);
                return;
            }
        }
    }

    const request = crossOrigin
        ? new Request(url, { cache: 'reload', mode: 'cors', credentials: 'omit' })
        : new Request(url, { cache: 'reload' });

    const response = await fetch(request);

    if (!cacheable(response)) {
        throw new Error(`pwax sw: ${url} responded ${response.status} and was not stored`);
    }

    await cache.put(url, response);
}

/** Every URL in a prefetch asset group, deduplicated and in manifest order. */
function prefetchUrls(manifest) {
    const urls = [];

    for (const group of manifest.assetGroups || []) {
        if (group.installMode === 'lazy') {
            continue;
        }

        for (const url of group.urls || []) {
            if (!urls.includes(url)) {
                urls.push(url);
            }
        }
    }

    return urls;
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

async function route(event) {
    const request = event.request;
    const manifest = await state();

    if (request.mode === 'navigate') {
        return navigate(event, manifest);
    }

    const precache = await precacheFor(manifest);

    // Precached entries are content-addressed by the manifest hash, so a hit is by
    // definition the right version and there is nothing to revalidate.
    if (precache) {
        const hit = await precache.match(request);

        if (hit) {
            return hit;
        }
    }

    const url = new URL(request.url);
    const ours = (manifest.assetPrefixes || []).some((prefix) => url.pathname.startsWith(prefix));

    if (ours) {
        return staleWhileRevalidate(request, manifest);
    }

    return manifest.strategy === 'stale-while-revalidate'
        ? staleWhileRevalidate(request, manifest)
        : networkFirst(request, manifest);
}

/**
 * Navigations go to the network, and their responses are never stored.
 *
 * Storing them is the obvious thing to do and it is wrong here. The Cache API ignores
 * HTTP cache directives, so caching a navigation persists to disk exactly the documents
 * the server marked `no-store, private` — a signed-in user's rendered page, which the
 * next person to use that device would then be served offline. The offline shell exists
 * so that offline navigation does not require that trade: it is the same SPA shell with
 * no session and no page data, and the runtime routes from it as normal.
 */
async function navigate(event, manifest) {
    try {
        const preloaded = manifest.navigationPreload !== false ? await event.preloadResponse : null;

        if (preloaded) {
            return preloaded;
        }

        return await fetch(event.request);
    } catch {
        for (const url of [manifest.offlineUrl, manifest.shellUrl]) {
            if (!url) {
                continue;
            }

            const hit = await caches.match(url);

            if (hit) {
                return hit;
            }
        }

        return new Response(OFFLINE_HTML, {
            status: 503,
            headers: { 'Content-Type': 'text/html; charset=utf-8' },
        });
    }
}

async function networkFirst(request, manifest) {
    try {
        const response = await fetch(request);
        await put(request, response.clone(), manifest);

        return response;
    } catch (error) {
        const cached = await caches.match(request);

        if (cached) {
            return cached;
        }

        throw error;
    }
}

async function staleWhileRevalidate(request, manifest) {
    const cached = await caches.match(request);

    const network = fetch(request)
        .then(async (response) => {
            await put(request, response.clone(), manifest);
            return response;
        })
        .catch(() => cached);

    return cached || network;
}

/* ----------------------------------------------------------------- cache writes ----- */

async function put(request, response, manifest) {
    if (!cacheable(response)) {
        return;
    }

    const cache = await caches.open(runtimeName(manifest));
    await cache.put(request, response);
    await trim(cache, manifest);
}

/**
 * Is this response one we are allowed to keep?
 *
 * An opaque response has status 0 and an unreadable body; caching one wastes quota and
 * can serve an error page forever. Partial content is equally unsafe to store. And
 * `no-store` is the server saying, in the only way it has, that this body belongs to one
 * person and one moment — component modules and page payloads carrying user data say
 * exactly that, and honouring it is what keeps them off disk.
 */
function cacheable(response) {
    if (!response || !response.ok || response.status === 206 || response.type === 'opaque') {
        return false;
    }

    return !/(^|,)\s*no-store\s*(,|$)/i.test(response.headers.get('Cache-Control') || '');
}

/**
 * Keep the runtime cache bounded.
 *
 * Only the runtime cache. Precached entries live in their own cache and are never
 * trimmed, so ordinary browsing can no longer evict the app shell or the framework and
 * quietly take the application offline-capability away.
 */
async function trim(cache, manifest) {
    const max = manifest.maxEntries || 0;

    if (max <= 0) {
        return;
    }

    const keys = await cache.keys();

    if (keys.length <= max) {
        return;
    }

    await Promise.all(keys.slice(0, keys.length - max).map((key) => cache.delete(key)));
}

async function clearCaches() {
    const keys = await caches.keys();

    await Promise.all(keys.filter((key) => key.startsWith(`${PREFIX}-`)).map((key) => caches.delete(key)));

    statePromise = null;
}

/* ------------------------------------------------------------------------ state ----- */

function precacheName(manifest) {
    return `${PREFIX}-precache-${manifest.version || 'v1'}-${manifest.hash}`;
}

function runtimeName(manifest) {
    return `${PREFIX}-runtime-${manifest.version || 'v1'}`;
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

    for (const cache of previous) {
        try {
            const stored = await cache.match(MANIFEST_URL);

            if (!stored) {
                continue;
            }

            const manifest = await stored.json();

            for (const [url, hash] of Object.entries(manifest.hashTable || {})) {
                hashes.set(url, hash);
            }
        } catch {
            // A cache without a readable manifest simply contributes nothing.
        }
    }

    return hashes;
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
