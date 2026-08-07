{{--
    Default Pwax service worker.

    Publish and edit with:
        php artisan vendor:publish --tag=pwax-service-worker
--}}
@php
    $version = config('pwax.service_worker.version', 'v1');
    $prefix = config('pwax.service_worker.cache_name', 'pwax');
    $precache = array_values(array_unique(array_filter((array) config('pwax.service_worker.precache', ['/']))));
    $strategy = config('pwax.service_worker.strategy', 'network-first');
    $offlineUrl = config('pwax.service_worker.offline_url');
    $maxEntries = (int) config('pwax.service_worker.max_entries', 60);
    $assetPrefixes = array_values(array_unique(array_filter([
        '/' . trim((string) config('pwax.route_prefix', '__pwax__'), '/') . '/',
        '/' . trim((string) config('pwax.assets.local_path', '/vendor/pwax'), '/') . '/',
    ])));
@endphp
const VERSION = @json($version);
const PREFIX = @json($prefix);
const CACHE = `${PREFIX}-${VERSION}`;
const PRECACHE_URLS = @json($precache, JSON_UNESCAPED_SLASHES);
const STRATEGY = @json($strategy);
const OFFLINE_URL = @json($offlineUrl, JSON_UNESCAPED_SLASHES);
const MAX_ENTRIES = @json($maxEntries);
const ASSET_PREFIXES = @json($assetPrefixes, JSON_UNESCAPED_SLASHES);

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            // `reload` bypasses the HTTP cache, so installing never precaches a copy
            // that is already stale.
            .then((cache) => cache.addAll(PRECACHE_URLS.map((url) => new Request(url, { cache: 'reload' }))))
            .catch((error) => console.warn('pwax sw: precache failed', error))
    );

    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            // Only delete our own caches. Another worker, or another library, may own
            // caches on this origin and deleting those would be someone else's outage.
            const keys = await caches.keys();
            await Promise.all(
                keys.filter((key) => key.startsWith(`${PREFIX}-`) && key !== CACHE).map((key) => caches.delete(key))
            );

            if (self.registration.navigationPreload) {
                await self.registration.navigationPreload.enable();
            }

            await self.clients.claim();
        })()
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'PWAX_SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Only GET is cacheable, and only our own origin is ours to cache. Range requests
    // are skipped because a partial response cached as a whole one corrupts media
    // playback.
    if (request.method !== 'GET' || request.headers.has('range')) {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(handleNavigation(event));
        return;
    }

    if (ASSET_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
        event.respondWith(staleWhileRevalidate(request));
        return;
    }

    event.respondWith(STRATEGY === 'stale-while-revalidate' ? staleWhileRevalidate(request) : networkFirst(request));
});

/**
 * Navigations always try the network first: serving a stale HTML shell would pin the
 * user to an old build indefinitely.
 */
async function handleNavigation(event) {
    try {
        const preloaded = await event.preloadResponse;

        if (preloaded) {
            await put(event.request, preloaded.clone());
            return preloaded;
        }

        const response = await fetch(event.request);
        await put(event.request, response.clone());

        return response;
    } catch {
        return (
            (await caches.match(event.request)) ||
            (OFFLINE_URL ? await caches.match(OFFLINE_URL) : null) ||
            (await caches.match('/')) ||
            new Response('<h1>Offline</h1><p>This page is not available offline.</p>', {
                status: 503,
                headers: { 'Content-Type': 'text/html; charset=utf-8' },
            })
        );
    }
}

async function networkFirst(request) {
    try {
        const response = await fetch(request);
        await put(request, response.clone());

        return response;
    } catch (error) {
        const cached = await caches.match(request);

        if (cached) {
            return cached;
        }

        throw error;
    }
}

async function staleWhileRevalidate(request) {
    const cached = await caches.match(request);

    const network = fetch(request)
        .then(async (response) => {
            await put(request, response.clone());
            return response;
        })
        .catch(() => cached);

    return cached || network;
}

async function put(request, response) {
    // An opaque response has status 0 and an unreadable body; caching one wastes quota
    // and can serve an error page forever. Partial content is equally unsafe to store.
    if (!response || !response.ok || response.status === 206 || response.type === 'opaque') {
        return;
    }

    const cache = await caches.open(CACHE);
    await cache.put(request, response);
    await trim(cache);
}

/**
 * Keep the cache bounded. Without a cap, a long-lived install grows until the browser
 * evicts the whole origin — which takes the precached shell with it.
 */
async function trim(cache) {
    if (MAX_ENTRIES <= 0) {
        return;
    }

    const keys = await cache.keys();

    if (keys.length <= MAX_ENTRIES) {
        return;
    }

    await Promise.all(keys.slice(0, keys.length - MAX_ENTRIES).map((key) => cache.delete(key)));
}
