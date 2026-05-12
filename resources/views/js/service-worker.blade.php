{{-- 
    Default Pwax service worker. Publish with:
        php artisan vendor:publish --tag=pwax-service-worker
    Then edit resources/views/vendor/pwax/js/service-worker.blade.php
--}}
@php
    $cacheName = config('pwax.service_worker.cache_name', 'pwax-cache-v1');
    $precache = config('pwax.service_worker.precache', ['/']);
    $networkFirst = config('pwax.service_worker.network_first', true);
@endphp
const CACHE_NAME = @json($cacheName);
const PRECACHE_URLS = @json($precache);
const NETWORK_FIRST = @json($networkFirst);

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS)).catch(() => {})
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    if (NETWORK_FIRST) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, copy)).catch(() => {});
                    return response;
                })
                .catch(() => caches.match(request))
        );
    } else {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                const copy = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(request, copy)).catch(() => {});
                return response;
            }))
        );
    }
});
