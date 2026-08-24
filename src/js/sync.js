/**
 * Queuing a write for when the connection comes back.
 *
 * An offline-capable app that can only *read* offline is half an app. This is the other
 * half: a form submitted with no network is stored, the worker is asked to replay it, and
 * Background Sync wakes the worker to do so when connectivity returns — or, on a browser
 * without Background Sync, it is replayed the moment anything asks.
 *
 * Deliberately explicit. Nothing is queued for you: intercepting failed writes
 * automatically would replay a payment twice as readily as a draft, and only the
 * application knows which of its requests are safe to repeat.
 */

const QUEUE_PREFIX = '/__pwax__/sync/';

/**
 * The freshest CSRF token this document has.
 *
 * The meta tag rather than the boot-time config: a login redirects and the new document
 * carries the new token, while `config.csrf` is whatever was current when the runtime
 * started. The worker asks for this before it replays anything, because the token stored
 * with a queued write is the one that was current when it was queued — see
 * `registerSync()` in `sw/push.js`.
 */
function currentToken(config) {
    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta?.getAttribute('content') || config.csrf || null;
}

/**
 * @param {{ cachePrefix?: string, csrf?: string|null }} config
 * @param {{ headers: (extra?: object) => object }} http
 */
export function createSyncApi(config, http) {
    const cacheName = `${config.cachePrefix || 'pwax'}-sync`;

    const controller = () => navigator.serviceWorker && navigator.serviceWorker.controller;

    /** Ask the worker to drain the queue, one way or another. */
    const kick = () => {
        controller()?.postMessage({ type: 'PWAX_SYNC_REGISTER' });
    };

    // Answer the worker when it asks what this session's token is. Registered once, at
    // construction, because a Background Sync wake can reach a page that has been open for
    // hours and is not otherwise doing anything.
    //
    // Guarded on the method rather than on the property: `navigator.serviceWorker` exists
    // in every browser that has a worker at all, but not everything that stands in for it
    // is an EventTarget, and throwing here would take the whole queue API down with it.
    if (typeof navigator.serviceWorker?.addEventListener === 'function') {
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (!event.data || event.data.type !== 'PWAX_SYNC_TOKEN') {
                return;
            }

            event.ports?.[0]?.postMessage({
                type: 'PWAX_SYNC_TOKEN',
                token: currentToken(config),
            });
        });
    }

    /**
     * Is this URL on our own origin?
     *
     * It has to be. The headers stored with a queued write include this session's CSRF
     * token, and the worker replays them verbatim — so a cross-origin URL here, whether a
     * typo or a third-party API somebody meant to call, hands that token to another origin
     * from a context the page cannot see. `push.js` refuses the same thing for
     * `pwax.push.endpoint`, for the same reason and in the same words.
     */
    const isSameOrigin = (url) => {
        try {
            return new URL(url, window.location.origin).origin === window.location.origin;
        } catch {
            return false;
        }
    };

    return {
        get supported() {
            return 'serviceWorker' in navigator && 'caches' in window;
        },

        /**
         * Queue a request to be sent when the network allows.
         *
         * Stored as JSON in a cache the worker shares, keyed on a unique URL so two
         * queued writes never collide. Returns false when there is nothing to store it
         * in, so a caller can fall back to failing loudly rather than silently dropping
         * what someone typed.
         *
         * @param {string} url
         * @param {{method?: string, headers?: object, body?: any}} options
         */
        async enqueue(url, { method = 'POST', headers = {}, body = null } = {}) {
            if (!('caches' in window)) {
                return false;
            }

            if (!isSameOrigin(url)) {
                console.error(
                    `pwax: sync.enqueue() only accepts URLs on this origin, got "${url}". ` +
                        "Nothing was queued — replaying it would send this session's CSRF " +
                        'token to another origin.'
                );

                return false;
            }

            const payload = {
                url: new URL(url, window.location.origin).href,
                method,
                headers: { ...http.headers(), ...headers },
                body: typeof body === 'string' || body === null ? body : JSON.stringify(body),
            };

            try {
                const cache = await caches.open(cacheName);
                const key = `${QUEUE_PREFIX}${Date.now()}-${Math.random().toString(36).slice(2)}`;

                await cache.put(key, new Response(JSON.stringify(payload)));
            } catch {
                return false;
            }

            kick();

            document.dispatchEvent(new CustomEvent('pwax:queued', { detail: { url } }));

            return true;
        },

        /**
         * How many requests are waiting.
         *
         * Read from the cache directly rather than asked of the worker: this has to answer
         * before a worker is controlling the page, which is exactly when someone reloads
         * and wants to know their draft is still going to send.
         */
        async pending() {
            if (!('caches' in window) || !(await caches.has(cacheName))) {
                return 0;
            }

            try {
                return (await (await caches.open(cacheName)).keys()).length;
            } catch {
                return 0;
            }
        },

        /** Try the queue now, without waiting for a connectivity change. */
        flush() {
            kick();
        },
    };
}
