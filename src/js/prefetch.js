/**
 * Fetching a page just before it is asked for.
 *
 * With the service worker off — the default — every navigation is a cold round trip, and
 * the gap between clicking a link and seeing the page is entirely that request. But a
 * visitor tells you where they are going before they go: the pointer arrives on a link
 * some hundreds of milliseconds before the click, and a keyboard user focuses it first.
 * Spending that time on the request makes the navigation feel instant without making it
 * any less correct — it is the same request, sent earlier.
 *
 * Bounded on purpose. Page payloads can carry a signed-in visitor's data, so they are held
 * in memory only, never written to disk, capped, and dropped after a few seconds. A
 * prefetch is a head start, not a cache.
 */

const MAX_ENTRIES = 8;
const TTL = 30_000;

/**
 * @param {{ json: (url: string, options?: object) => Promise<any> }} http
 * @param {{ mode?: string|false, delay?: number }} config
 */
export function createPrefetcher(http, config = {}) {
    /** @type {Map<string, {at: number, payload: Promise<any>}>} */
    const entries = new Map();

    const mode = config.mode ?? 'hover';
    const delay = config.delay ?? 65;

    let timer = null;

    const fresh = (entry) => entry && Date.now() - entry.at < TTL;

    /**
     * The in-app path a link points at, or null if it is not one of ours.
     *
     * Anything that leaves the origin, opens a new tab, downloads, or carries a modifier
     * is a navigation the router will not handle, so prefetching it would be a request
     * nobody uses.
     */
    const pathFor = (anchor) => {
        if (
            !anchor ||
            !anchor.href ||
            anchor.target === '_blank' ||
            anchor.hasAttribute('download') ||
            anchor.dataset.pwaxPrefetch === 'off'
        ) {
            return null;
        }

        let url;

        try {
            url = new URL(anchor.href);
        } catch {
            return null;
        }

        if (url.origin !== window.location.origin) {
            return null;
        }

        const path = url.pathname + url.search;

        // The page they are already on, and in-page anchors, are not navigations.
        return path === window.location.pathname + window.location.search ? null : path;
    };

    const api = {
        /**
         * Fetch a page's payload now, and remember it briefly.
         *
         * Safe to call repeatedly for the same path — the in-flight promise is shared, so
         * a pointer moving in and out of a link costs one request.
         */
        prefetch(path) {
            const existing = entries.get(path);

            if (fresh(existing)) {
                return existing.payload;
            }

            // Oldest out first. Eight is more than a visitor can plausibly be about to
            // click, and each entry is a whole page payload.
            if (entries.size >= MAX_ENTRIES) {
                entries.delete(entries.keys().next().value);
            }

            const payload = http.json(path).catch(() => {
                // A failed prefetch is not an error anyone asked about. Forget it and let
                // the real navigation fail properly, with its own error handling.
                entries.delete(path);

                return null;
            });

            entries.set(path, { at: Date.now(), payload });

            return payload;
        },

        /**
         * Take a prefetched payload, if there is a usable one.
         *
         * Removed as it is taken. A payload is good for the navigation it was fetched for
         * and should not be reused for a later visit to the same URL — that is what the
         * service worker's page cache is for, with rules about it.
         */
        take(path) {
            const entry = entries.get(path);

            entries.delete(path);

            return fresh(entry) ? entry.payload : null;
        },

        /** Stop watching. Used by tests and on teardown. */
        stop() {
            window.clearTimeout(timer);
            document.removeEventListener('pointerenter', onIntent, true);
            document.removeEventListener('focusin', onIntent, true);
            document.removeEventListener('pointerleave', onLeave, true);
        },
    };

    function onIntent(event) {
        const anchor = event.target?.closest?.('a[href]');
        const path = pathFor(anchor);

        if (!path) {
            return;
        }

        // A pointer crossing a list of links should not fetch every one of them. The
        // delay is short enough to be invisible and long enough to mean intent.
        window.clearTimeout(timer);
        timer = window.setTimeout(() => api.prefetch(path), delay);
    }

    function onLeave() {
        window.clearTimeout(timer);
    }

    if (mode !== false && mode !== 'off') {
        // Capturing, because `pointerenter` does not bubble.
        document.addEventListener('pointerenter', onIntent, true);
        document.addEventListener('focusin', onIntent, true);
        document.addEventListener('pointerleave', onLeave, true);
    }

    return api;
}
