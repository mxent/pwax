{{--
    Default Pwax service worker.

    Driven by the asset manifest at `sw.json`: the server enumerates every URL the
    application is made of with a content hash, and this installs the lot in one pass. A
    visitor who has loaded one page can go offline and still reach every route and every
    component.

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
        'navigationStrategy' => (string) ($manifest['navigationStrategy'] ?? 'network-first'),
        'navigationUrls' => array_values((array) ($manifest['navigationUrls'] ?? [])),
        'shellUrl' => $manifest['shellUrl'] ?? null,
        'offlineUrl' => $manifest['offlineUrl'] ?? null,
        'assetPrefixes' => array_values((array) ($manifest['assetPrefixes'] ?? [])),
        'pageHeaders' => (array) ($manifest['pageHeaders'] ?? []),
        'crossOrigin' => array_values((array) ($manifest['crossOrigin'] ?? [])),
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
 * Who the visitor caches currently belong to.
 *
 * In the state cache rather than in memory, because the browser kills an idle worker
 * routinely and one that woke with no record would treat the next response as the first
 * and never notice that the person had changed.
 */
const IDENTITY_KEY = '/__pwax__/sw-identity';

/**
 * How many assets to precache at once.
 *
 * Six, matching what a browser opens to one origin anyway. The number that matters is
 * that it is bounded: PHP's built-in server — `php artisan serve`, what most people
 * develop against — handles one request at a time.
 */
const CONCURRENCY = 6;

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

/** Third-party URLs this build precached, and the only ones we answer off-origin. */
const CROSS_ORIGIN = new Set(CONFIG.crossOrigin || []);

/**
 * The last resort: a navigation with no network and nothing stored for that URL.
 *
 * A whole document, so it carries its own styles — the shell's stylesheet belongs to a
 * page that never loaded. It is written to match the application's other screens rather
 * than to stand out: a visitor who has already seen the in-app error should recognise this
 * as the same thing said by something further down the stack, not as a second, worse
 * failure.
 *
 * No script, and no reload button that reloads on its own. Reloading is exactly what will
 * fail again; the browser's own control is the honest place for that.
 */
const OFFLINE_HTML =
    '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width, initial-scale=1">' +
    '<meta name="color-scheme" content="light dark"><title>Offline</title><style>' +
    ':root{--fg:#18181b;--muted:#71717a;--line:#e4e4e7;--bg:#ffffff}' +
    '@media(prefers-color-scheme:dark){:root{--fg:#fafafa;--muted:#a1a1aa;--line:#3f3f46;--bg:#09090b}}' +
    'html,body{margin:0;height:100%}' +
    'body{display:flex;align-items:center;justify-content:center;padding:2rem 1.5rem;' +
    'background:var(--bg);color:var(--fg);line-height:1.5;' +
    'font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}' +
    'div{width:100%;max-width:32rem;text-align:center}' +
    'p.c{margin:0 0 1rem;font-size:.75rem;font-weight:600;letter-spacing:.1em;' +
    'text-transform:uppercase;color:var(--muted)}' +
    'p.c::after{content:"";display:block;width:2.5rem;height:1px;margin:.75rem auto 0;background:var(--line)}' +
    'h1{margin:0 0 .5rem;font-size:1.375rem;font-weight:600;letter-spacing:-.01em}' +
    'p.m{margin:0;color:var(--muted)}' +
    '</style></head><body><div role="alert">' +
    '<p class="c">Offline</p><h1>This page is not available offline</h1>' +
    '<p class="m">It has not been stored on this device. Reconnect and try again.</p>' +
    '</div></body></html>';

/**
 * What a request says about who is asking, or null when it says nothing.
 *
 * The distinction is load-bearing. Every request the client runtime makes carries this
 * header — `anon` included, deliberately — so that "a guest is asking" and "this is not a
 * Pwax request at all" are different signals. A browser fetching an image says nothing,
 * and reading that as a sign-out would empty the caches on the next asset a page loads.
 *
 * The value is an opaque HMAC minted by the server (`Shell::identity()`); the worker only
 * ever compares it, never interprets it.
 */
function declaredIdentity(request) {
    return (request && request.headers && request.headers.get('X-Pwax-Identity')) || null;
}

/**
 * Who a response was actually rendered for, or null when nothing said.
 *
 * The request cannot answer this on its own. Signing in through the runtime is a
 * client-side navigation by design, so the request that carries a visitor into their
 * account still holds the identity they had before it. The response knows:
 * `ComponentResponse` sends the identity it rendered for, on the payload and on the HTML
 * alike, and the request is consulted only when the response is silent.
 */
function identityFor(request, response) {
    const declared = response && response.headers && response.headers.get('X-Pwax-Identity');

    return declared || declaredIdentity(request);
}

/**
 * Who the visitor caches currently belong to.
 *
 * `undefined` until read, `null` when nothing has ever been recorded. Memoised because it
 * is consulted on every page response and the worker is a single instance; a worker the
 * browser has killed and restarted simply reads it back from the state cache.
 */
let knownIdentity = undefined;

async function currentIdentity() {
    if (knownIdentity === undefined) {
        const stored = await readManifest(IDENTITY_KEY);

        knownIdentity = stored && typeof stored.identity === 'string' ? stored.identity : null;
    }

    return knownIdentity;
}

/**
 * Notice that a different person is being served, and empty what belonged to the last one.
 *
 * This is what replaces naming caches after the visitor. The old scheme made a cross-user
 * read impossible by construction, which is stronger — but it also meant a new set of
 * caches per person, an empty one minted on every sign-in, and everything re-fetched under
 * the new name. One cache, emptied the moment the server says it is answering somebody
 * else, holds the property that actually matters on a shared device.
 *
 * Driven by the server's own statement rather than by anything the client sends, so it
 * cannot be skipped by a page that forgot to call `forgetIdentity()`. The window it leaves
 * is one response wide: the response that announces the change is written after the wipe,
 * never before.
 *
 * `null` on the first ever response is recorded and nothing is wiped — there is nothing
 * there to belong to anyone.
 */
async function syncIdentity(manifest, identity) {
    // A request that claims nothing is not a claim that nobody is signed in.
    if (!identity) {
        return;
    }

    const known = await currentIdentity();

    if (known === identity) {
        return;
    }

    knownIdentity = identity;
    await writeManifest(IDENTITY_KEY, { identity });

    if (known !== null) {
        await wipeVisitorCaches(manifest);
    }
}

/** Is anybody signed in on this device? */
async function signedInHere() {
    const identity = await currentIdentity();

    return identity !== null && identity !== 'anon';
}

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
 * `credentials: 'omit'` by default, and that matters. A page is only precacheable because
 * it renders the same for everyone — that is what `->cacheable()` asserts — so the
 * anonymous rendering is the correct one to store. Fetching with cookies would precache
 * whichever user happened to trigger the install, on a device they may share.
 */
function pageRequest(url, credentials) {
    return new Request(url, {
        headers: CONFIG.pageHeaders || {},
        credentials: credentials || 'omit',
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

    // Sign-out. Drops one person's pages, runtime entries and API responses while leaving
    // the precache — the framework, the components, the shell — in place, so the next
    // person to use the device gets an application that still works offline rather than
    // one that has to download itself again.
    if (type === 'PWAX_FORGET_IDENTITY') {
        event.waitUntil(
            state()
                .then((manifest) => forgetVisitor(manifest))
                .then(() => reply(event, { type: 'PWAX_IDENTITY_FORGOTTEN' }))
        );
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
        // Third-party requests are none of our business — with one exception. An asset the
        // manifest deliberately precached from another origin, a CDN copy of Vue under
        // `assets.strategy = 'cdn'` or anything in `pwax.scripts` pointing off-site, was
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

    // Opened together. Nothing here depends on anything else here — the two caches are
    // named from the manifest and the previous precaches are found by listing — so
    // awaiting them in turn only added the storage layer's latency up.
    //
    // Page payloads go to their own cache, which every identity can read and none can
    // write to. What is fetched here is the guest rendering — cookieless, the same fetch
    // that produces the precached document — so withholding it from a signed-in visitor
    // protected nobody and broke the feature for them: offline, a page they had not
    // already opened this session was the one page precaching could not give them.
    const [cache, pages, previous] = await Promise.all([
        caches.open(cacheName),
        caches.open(installedPagesName(manifest)),
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
    const results = await settleWithLimit(entries, CONCURRENCY, (entry) =>
        store(entry.kind === 'page' ? pages : cache, entry.url, {
            hash: (manifest.hashTable || {})[entry.url],
            inherited,
            previous,
            crossOrigin: crossOrigin.has(entry.url),
            kind: entry.kind,
            credentials: entry.credentials,
            documents: entry.kind === 'page' ? cache : null,
        })
    );

    const failures = urls.filter((_, i) => results[i].status === 'rejected');

    // Kept apart from failures. A response the server marked `no-store` was not lost, it
    // was refused — every page rendered by `pwaxRender()` says exactly that, on
    // purpose, so that one visitor's HTML is never written to another's disk. Reporting
    // that as "could not be precached" reads like a broken install and sends people
    // looking for a network problem that is not there.
    const skipped = urls.filter((_, i) => results[i].status === 'fulfilled' && results[i].value === false);
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

    installed = manifest;

    await writeManifest(PENDING_KEY, manifest);
}

async function activate() {
    const manifest = installed || (await readManifest(PENDING_KEY)) || (await readManifest(STATE_KEY)) || CONFIG;
    const keep = new Set([
        precacheName(manifest),
        installedPagesName(manifest),
        documentsName(manifest),
        pagesName(manifest),
        runtimeName(manifest),
        STATE_CACHE,
    ]);

    // Promoted before the sweep, so a worker terminated midway through cannot come back
    // and mistake the cache it is activating for a stale one.
    await writeManifest(STATE_KEY, manifest);
    statePromise = Promise.resolve(manifest);

    // Only our own caches. Another worker, or another library, may own caches on this
    // origin and deleting those would be someone else's outage.
    const keys = await caches.keys();

    // Every cache this build owns can now be named outright, which it could not when each
    // signed-in visitor had a set of their own. Data groups are the exception and are
    // matched by prefix: they are answers from an API rather than part of the build, so
    // they survive a deploy and an application that was working offline still is the moment
    // a new version installs.
    const live = [`${PREFIX}-data-`];
    const stale = keys.filter(
        (key) => key.startsWith(`${PREFIX}-`) && !keep.has(key) && !live.some((prefix) => key.startsWith(prefix))
    );

    await Promise.all(stale.map((key) => caches.delete(key)));

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
async function store(cache, url, { hash, inherited, previous, crossOrigin, kind, credentials, documents }) {
    const page = kind === 'page';

    if (hash && inherited.get(url) === hash) {
        for (const old of previous) {
            const copy = await old.match(url);

            if (copy) {
                await cache.put(url, copy);
                return true;
            }
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
    // This is also what keeps an authenticated route out of the anonymous precache: asked
    // for without cookies, it answers with the login screen and is refused here.
    if (page && !isJson(response)) {
        return false;
    }

    if (!(page ? storablePage(response) : cacheable(response))) {
        return false;
    }

    // Pages are keyed by the request the runtime will make, not by the bare URL, so the
    // response's `Vary` can be satisfied on lookup.
    await cache.put(page ? pageKey(url) : url, response);

    if (page && documents) {
        await storeDocument(documents, url, credentials);
    }

    return true;
}

/**
 * Store the page's rendered HTML, so an offline navigation gets the real document.
 *
 * The payload alone is enough to *work* offline — the shell boots and the runtime renders
 * from it — but it means a spinner and a second round trip on a device that already has
 * everything. The document has the component inlined in its `pwax-initial` island, so an
 * offline navigation paints the page immediately, exactly as an online one does.
 *
 * Deliberately in the precache rather than beside the payload. Both are the same URL, and
 * page responses vary on `Accept` among other things — a document stored next to the JSON
 * would need a key carrying the exact `Accept` string the browser sends on a navigation,
 * which differs between browsers and is not knowable here. Separate caches make each
 * lookup unambiguous. The precache is also simply where it belongs: fetched without
 * cookies, identical for every visitor, versioned and swept with the build, exactly like
 * the offline shell.
 *
 * Only ever called after the payload fetch succeeded, and that ordering is load-bearing.
 * A route behind `auth` answers an anonymous request with a login screen; as JSON that is
 * detectable and refused, as HTML it is indistinguishable from a real page. Requiring the
 * payload first is what stops a login screen being cached as somebody's Settings page.
 */
async function storeDocument(cache, url, credentials) {
    try {
        const response = await fetch(
            new Request(url, { cache: 'reload', credentials: credentials || 'omit' })
        );

        if (response.ok && response.status !== 206 && storablePage(response)) {
            await cache.put(url, response);
        }
    } catch {
        // The payload is already stored and is what offline correctness depends on. A
        // document that could not be fetched costs a spinner, not a broken page.
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
            entries.push({ url, kind: group.kind === 'page' ? 'page' : 'asset', credentials: group.credentials });
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
        return page(request, manifest, group);
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
        return lazyAsset(request, manifest, lazy);
    }

    const data = dataGroupFor(manifest, key);

    if (data) {
        return dataResponse(request, manifest, data);
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
    return manifest.pageRuntime === false ? null : manifest.pageDefaults || { strategy: 'freshness' };
}

/**
 * A page payload: from the network when there is one, from this identity's cache when
 * there is not.
 *
 * This is the request that used to fail. The runtime fetches the page it is navigating to
 * with `mode: 'cors'` rather than `'navigate'`, so it never reached the shell fallback —
 * it fell through to the generic network-first path, missed, and surfaced as "This page
 * needs an internet connection to load" on a device that had the whole application
 * installed.
 */
async function page(request, manifest, group) {
    // Before the read, from the request's own claim. The write path syncs from the
    // response, which is the authority — but a response only arrives when the network
    // does, and the case this exists for is a visitor who is offline from the first
    // request. Without this, somebody signing in on a device where the last person's
    // pages are still stored would be served them, because nothing had yet told the
    // worker the person had changed.
    //
    // Stale for exactly one request, the one that signs somebody in, and stale in the
    // safe direction: it matches what is stored, so nothing is wiped and nothing is
    // served that was not already theirs.
    await syncIdentity(manifest, declaredIdentity(request));

    if (group.strategy === 'performance') {
        const hit = await storedPage(request, manifest);

        if (hit) {
            return hit;
        }
    }

    try {
        const response = await withTimeout(fetch(request), group.timeout);

        // Not a payload — a login screen, a maintenance page, a captive portal. Hand it
        // back so the runtime can act on it, but do not remember it as this page.
        if (isJson(response) && storablePage(response)) {
            const url = new URL(request.url);

            // Before the write, never after. The response's identity is the authority —
            // the request that signs somebody in still carries the identity they had
            // before it — so this is the moment the change is visible, and the moment the
            // last person's pages have to go. Wiping afterwards would leave the new
            // visitor's first page in a cache about to be emptied.
            await syncIdentity(manifest, identityFor(request, response));

            const cache = await caches.open(pagesName(manifest));

            await cache.put(pageKey(url.pathname + url.search), response.clone());
            await trim(cache, group.maxEntries || manifest.maxEntries);
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
 * This page as it was last stored: the visitor's own copy, then the one the build shipped.
 *
 * The same shape as `matchScoped()`, and for the same reason. Their own copy first,
 * because it was rendered for them and the build's was not. The build's as a fallback,
 * because it is cookieless and shared by construction — it is what a navigation to this
 * URL is already answered with offline, so refusing it to the runtime only meant a link
 * failed where a reload of the same URL succeeded.
 */
async function storedPage(request, manifest) {
    // `has` before `open` throughout: `caches.open` *creates*, and a read that brings a
    // cache into existence leaves an empty one behind for every visitor who merely looked
    // at a page. Those empty caches are litter, and they made the worker look as though it
    // held something for a route when it held nothing at all.
    for (const name of [pagesName(manifest), installedPagesName(manifest)]) {
        const cache = await openIfPresent(name);
        const hit = cache && (await cache.match(request, { ignoreVary: true }));

        if (hit) {
            return hit;
        }
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
 * A lazy group member: fetched on first use, then kept in the precache.
 *
 * The precache rather than the runtime cache, deliberately. A lazy asset is still part of
 * the application — it was declared in the manifest — so once it has been obtained it
 * should not be evicted by ordinary browsing, which is exactly what the runtime cache's
 * entry cap would eventually do to it.
 */
async function lazyAsset(request, manifest, group) {
    const cache = await caches.open(precacheName(manifest));
    const hit = await cache.match(request, { ignoreVary: true });

    if (hit) {
        return hit;
    }

    const response = await fetch(request);

    if (cacheable(response)) {
        await cache.put(request, response.clone());
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
async function dataResponse(request, manifest, group) {
    await syncIdentity(manifest, declaredIdentity(request));

    const config = group.cacheConfig || {};
    const name = dataName(manifest, group);
    const key = new URL(request.url).pathname + new URL(request.url).search;

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
            await syncIdentity(manifest, identityFor(request, response));

            const cache = await caches.open(name);

            await cache.put(request, response.clone());
            await stamp(cache, key);
            await trimData(cache, config.maxSize);
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

    if (config.strategy === 'performance') {
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
async function trimData(cache, maxSize) {
    if (!maxSize || maxSize <= 0) {
        return;
    }

    const keys = (await cache.keys()).filter((key) => !new URL(key.url).pathname.startsWith('/__pwax__/sw-age/'));

    if (keys.length <= maxSize) {
        return;
    }

    for (const key of keys.slice(0, keys.length - maxSize)) {
        const path = new URL(key.url).pathname + new URL(key.url).search;

        await cache.delete(key);
        await cache.delete(stampKey(path));
    }
}

/**
 * Navigations go to the network, and a guest rendering of one is kept.
 *
 * A page has two representations. The runtime asks for the payload and gets JSON; a
 * reload, a bookmark, a link from outside the app is a navigation and gets HTML with the
 * component inlined. Only the first was ever stored after install, so a route the build
 * did not precache — a dynamic one, or anything route discovery could not reach — had no
 * document at all, and reloading it offline fell all the way back to the shell.
 *
 * What is stored is deliberately narrow: only a response that *says* it was rendered for
 * a signed-out visitor, by carrying `X-Pwax-Identity: anon`. Not a response that merely
 * looks anonymous, and not one with no header at all — an unknown identity is treated as
 * somebody's, so a server that does not send it stores nothing.
 *
 * The reason for that narrowness is that a navigation is the one request whose sender the
 * worker cannot identify. The runtime's fetches carry an identity header; a document
 * request made by the browser carries cookies, which a worker cannot read. So there is no
 * way to decide *whose* document to hand back, and the only document that is safe to hand
 * to anybody is the one that belongs to nobody. Signed-in pages keep working offline the
 * way they already did: the shell boots and the runtime asks for the payload, and that
 * request does carry an identity.
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

    if (manifest.navigationStrategy === 'app-shell') {
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
            const stored = (await storedDocument(manifest, path)) || (await shellDocument(manifest));

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
            (await storedDocument(manifest, path)) || (await shellDocument(manifest)) || offlineDocument()
        );
    }
}

/**
 * Keep a navigation's HTML, when it is safe to hand back to anyone.
 *
 * `X-Pwax-Identity: anon`, present and explicit. A response with no header is not treated
 * as anonymous — an application that does not send one, or a route that is not a Pwax page
 * at all, would otherwise have a signed-in document filed where every visitor can read it.
 * Missing means unknown, and unknown means no.
 *
 * `redirected` is the HTML counterpart of the payload path's JSON check. A route behind
 * `auth` answers a signed-out navigation by redirecting to the login screen, and `fetch`
 * follows it silently — so without this the login page would be stored as that route's
 * document and served under its URL forever.
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
        !storablePage(response) ||
        (response.headers.get('X-Pwax-Identity') || '') !== 'anon'
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
        await trim(cache, defaults.maxEntries || manifest.maxEntries);
    } catch {
        // A full disk is the likely one, and this is the least important thing on it: the
        // payload is already stored and is what offline correctness rests on. A document
        // that could not be written costs a spinner. Swallowed rather than left to reject
        // inside `waitUntil`, where it would be reported as the worker failing.
    }
}

/**
 * The stored HTML for a path: what this device saw, then what the build shipped.
 *
 * Withheld once anyone has signed in on this device, and that is the whole of "use them
 * according to the request". Every document here is a signed-out rendering — the precached
 * ones were fetched at install without cookies, the runtime ones said so themselves — and
 * a signed-out rendering is the wrong answer for a signed-in visitor. It is not a leak:
 * nothing personal is in these caches. It is the page telling someone they are logged out
 * when they are not, and unlike a slow paint it does not correct itself, because the
 * document carries its own inlined payload and the runtime has no reason to refetch it.
 *
 * So a device with a signed-in bucket gets the shell instead, and the runtime's own
 * request — which carries an identity — decides what to render. That costs a spinner and
 * buys the right page. `pwax.sw.forgetIdentity()` on sign-out clears the bucket and the
 * fast path comes back.
 *
 * Note what this is *not*: the install bucket of guest payloads, which `storedPage()`
 * still falls back to for a signed-in visitor, is deliberately unguarded. The two look
 * like the same decision made twice, opposite ways, and they are not. Withholding a
 * document costs a spinner, because the shell then boots and asks for the payload — which
 * consults the visitor's own bucket first, and reaches the same guest copy only if they
 * have none. Withholding the payload would cost the page itself: there is nothing behind
 * it. One has a strictly better answer available and the other has nothing.
 */
async function storedDocument(manifest, path) {
    if (await signedInHere()) {
        return null;
    }

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
 *
 * Not scoped by identity, and does not need to be: this reads the precache by name, and
 * every document in it was fetched without cookies at install. It is the guest rendering
 * or it is not there. Compare `matchScoped()`, which exists because the *runtime* caches
 * hold one person's responses and must never be read across the partition.
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
 * Looked up by URL, never by matching the request — a navigation must never be answered
 * from the pages cache, whose entries are JSON.
 *
 * `caches.match` without a cache name is deliberate here and safe for the same reason it
 * is unsafe in `matchScoped()`: these two URLs address the session-free shell, which is
 * rendered with no session precisely so that it is the same document for every visitor.
 * There is no partition to cross.
 */
async function shellDocument(manifest) {
    for (const url of [manifest.offlineUrl, manifest.shellUrl]) {
        if (!url) {
            continue;
        }

        const hit = await caches.match(url, { ignoreVary: true });

        if (hit) {
            return hit;
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
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
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
 * Everything this identity is allowed to be answered from, and nothing else.
 *
 * The counterpart to `put()`: one function decides what a write may touch, so one function
 * decides what a read may see. Without that pairing the partitioning only held on the way
 * in — `caches.match(request)` with no cache name walks *every* cache on the origin, so the
 * offline fallback would answer one visitor from the cache named for another. Partitioning
 * that holds in one direction is not partitioning; it is a naming convention.
 *
 * Two caches, in this order:
 *
 *   1. This identity's runtime cache. Theirs alone, by name.
 *   2. The precache — shared on purpose. It holds the framework, the components and the
 *      shell: the application itself, identical for everyone, fetched without cookies at
 *      install. Partitioning it would make every visitor re-download the app and protect
 *      nothing, because there is nothing in it that belongs to anybody.
 */
async function matchScoped(request, manifest) {
    // The read gate for the runtime cache, and therefore where the same check the page
    // path makes has to happen too: a visitor who is offline from their first request
    // never produces a response for the write path to notice them by.
    await syncIdentity(manifest, declaredIdentity(request));

    const name = runtimeName(manifest);

    // `has` before `open`, because `open` creates. A read has no business bringing a cache
    // into existence: every visitor who merely looked at a page whose response could not
    // be stored would leave an empty cache named after them, which is both litter and a
    // list of who has used the device.
    if (await caches.has(name)) {
        const mine = await (await caches.open(name)).match(request);

        if (mine) {
            return mine;
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

    // The response's identity wins over the request's, for the same reason it does on the
    // page path: the request that signs someone in still carries who they were.
    await syncIdentity(manifest, identityFor(request, response));

    const cache = await caches.open(runtimeName(manifest));
    await cache.put(request, response);
    await trim(cache, manifest.maxEntries);
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
 * person and one moment — component modules and page payloads carrying user data say
 * exactly that, and honouring it is what keeps them off disk.
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
 * Deliberately *not* `cacheable()`, and the difference is the whole of offline page
 * support. A page payload is `no-store, private` by default — correctly, because it can
 * embed the signed-in user's data and must never reach a shared HTTP cache. Applying that
 * rule here too would mean the page cache only ever held routes that had called
 * `->cacheable()`, which is what "runtime page caching" quietly was until this existed:
 * an empty cache and an offline app that could render its shell and nothing else.
 *
 * What makes storing it safe is not the header, it is where it goes. The cache is named
 * after the signed-in identity, so another identity cannot reach these entries at all;
 * install-time copies are fetched without cookies, so what they hold is the guest
 * rendering; and `->offline(false)` refuses outright, for a page that must not touch disk
 * under any circumstances. `service_worker.pages.runtime` turns the whole thing off.
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
async function trim(cache, maxEntries) {
    const max = maxEntries || 0;

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

/**
 * Where page payloads this device has visited live.
 *
 * One cache, whoever is signed in. The identity used to be part of the name, which made a
 * cross-user read structurally impossible — and cost a fresh empty cache on every sign-in,
 * a set of them per person on a shared device, and a re-cache of everything each time the
 * name changed. The partition is now kept by *emptying* this cache the moment the server
 * says a different person is being served (`syncIdentity()`), which holds the same
 * property against the case that matters — the next person on the device — at the price of
 * losing the previous one's offline pages rather than parking them.
 *
 * Keyed by the build, because a page payload embeds the compiled component: one stored
 * before a deploy holds the previous build's markup, and serving it into this build's
 * shell would render last week's page.
 */
function pagesName(manifest) {
    return `${PREFIX}-pages-${manifest.version || 'v1'}-${manifest.hash}`;
}

/**
 * Where the build's own guest page payloads live.
 *
 * Not a person and never wiped by a sign-in: this is what `install()` fetched without
 * cookies, the copies every visitor falls back to when they have none of their own. It is
 * swept with the build like the precache, and it is the reason a signed-in visitor can
 * open a page offline that they have never visited.
 */
function installedPagesName(manifest) {
    return pagesName(manifest) + '-install';
}

/**
 * Where navigation HTML lives.
 *
 * Everything in here is a signed-out rendering — `rememberDocument()` stores nothing else —
 * so it survives an identity change untouched. Keyed by the build like the pages cache,
 * because a document has the compiled component inlined.
 */
function documentsName(manifest) {
    return `${PREFIX}-documents-${manifest.version || 'v1'}-${manifest.hash}`;
}

/**
 * Where runtime-cached assets live. Emptied on an identity change, like the pages cache:
 * a response to a signed-in request can be as personal as a page.
 *
 * Deliberately not keyed by the build. These are URLs the application declared rather than
 * ones this build compiled, and re-downloading them on every deploy is exactly the churn
 * this cache exists to avoid.
 */
function runtimeName(manifest) {
    return `${PREFIX}-runtime-${manifest.version || 'v1'}`;
}

function dataName(manifest, group) {
    return `${PREFIX}-data-${group.name}-${group.version || 1}`;
}

/**
 * The explicit sign-out call, behind `pwax.sw.forgetIdentity()`.
 *
 * `syncIdentity()` already empties these the moment the server says somebody else is being
 * served, so this is belt and braces — and it is the one that runs when the sign-out is the
 * last thing that happens before the tab closes and no response follows it.
 */
async function forgetVisitor(manifest) {
    knownIdentity = 'anon';
    await writeManifest(IDENTITY_KEY, { identity: 'anon' });
    await wipeVisitorCaches(manifest);
}

/**
 * Empty everything that belongs to whoever was using this device.
 *
 * The pages they visited, the API responses they were served, the assets fetched under
 * their session. Not the precache, not the install bucket, not the documents cache: those
 * are the build's own, fetched without cookies, and belong to nobody — dropping them would
 * make every sign-in re-download the application for no gain.
 */
async function wipeVisitorCaches(manifest) {
    const keys = await caches.keys();
    const names = keys.filter(
        (key) =>
            key === pagesName(manifest) ||
            key === runtimeName(manifest) ||
            key.startsWith(`${PREFIX}-data-`)
    );

    await Promise.all(names.map((key) => caches.delete(key)));
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
