/**
 * Serving a page from memory when the visitor goes back to it.
 *
 * A router turns the back button into an ordinary navigation: the URL changes, the page
 * component asks the server what to render, and the visitor waits for a page they were
 * looking at a moment ago. A server-rendered site does not do this — the browser keeps its
 * own back/forward cache and restores the previous document without a request — so moving
 * an application to a router is what *introduces* the wait, on the one navigation where a
 * visitor is most certain of what they are about to see.
 *
 * This is the back/forward cache the router took away. Every page that mounts is kept, and
 * a *restoration visit* — a navigation the browser started, meaning back or forward — is
 * answered from that store with no request at all.
 *
 * The vocabulary is Turbo's, which named the two halves of this: a restoration visit is
 * what back and forward produce, as opposed to an application visit that a link click
 * produces, and the snapshot cache is what the first is answered from. Inertia arrives at
 * the same split by keeping page props in `history.state`. Both restore only on history
 * navigation, and this does too: a link click to a URL you have seen before still fetches,
 * because clicking a link is a request for the current state of that page, while going
 * back is a request for the page you were just on.
 *
 * That distinction is the whole design, and it has a consequence worth stating plainly:
 * going back shows the page as it was, not as it now is. Comment on a post and press back
 * and the list you return to is the list you left. That is what back means here, and it is
 * what the browser's own bfcache does for a server-rendered site. An application that needs
 * a particular page to always be current can drop it with `window.pwax.restore.forget(path)`
 * after the mutation, drop everything with `clear()`, or opt the page out entirely.
 *
 * Held in memory only, never written to disk, and capped. A page payload can carry a
 * signed-in visitor's data, so it lives as long as the document does and no longer: a
 * reload, a new tab, or closing the browser leaves nothing behind. `sessionStorage` would
 * survive a reload — it is what SvelteKit's snapshots use — and is deliberately not used
 * here for that reason. The prefetcher takes the same position for the same reason.
 *
 * Unlike the prefetcher, entries do not expire. A prefetch is a guess about where somebody
 * is going and is stale within seconds; a restoration entry is a record of where they have
 * actually been, and "the page I was just looking at" does not become wrong because a
 * minute passed. The cap is what bounds it instead.
 */

/** Pages kept before the least recently used one is dropped. */
const MAX_ENTRIES = 12;

/**
 * @param {{enabled?: boolean, entries?: number}|false} config
 * @param {Window|EventTarget} target - injectable for tests
 */
export function createRestore(config = {}, target = window) {
    const settings = config === false ? { enabled: false } : config || {};
    const enabled = settings.enabled !== false;

    // A cap of zero is a legitimate way to say "off" and must not be read as "unbounded".
    // `Math.max` also protects the eviction loop below from a negative.
    const limit = Math.max(0, Number.isFinite(settings.entries) ? settings.entries : MAX_ENTRIES);

    /** @type {Map<string, unknown>} path → the payload that rendered it. */
    const entries = new Map();

    /**
     * Whether the navigation now being processed was started by the browser.
     *
     * `popstate` fires for back, forward, and `router.go()`, and does not fire for
     * `pushState` — which is exactly the line between a restoration visit and an
     * application one, and is how both Turbo and Vue Router's own `scrollBehavior`
     * recognise it. There is no equivalent signal on the route object: by the time a
     * navigation guard runs, a pop and a push look identical.
     *
     * Read once per navigation and cleared, so a pop that misses the cache cannot leave
     * the flag set for the *next* navigation, which would then serve a cached page for a
     * link click.
     */
    let restoring = false;

    const onPopState = () => {
        restoring = true;
    };

    if (enabled) {
        target.addEventListener('popstate', onPopState);
    }

    return {
        /**
         * Keep the payload that rendered a path.
         *
         * Re-remembering a path already held moves it to the end of the map, which is what
         * makes eviction least-recently-used rather than first-in: a page visited
         * repeatedly should not be dropped because it was first seen a long time ago.
         */
        remember(path, payload) {
            if (!enabled || !limit || !path || !payload) {
                return;
            }

            entries.delete(path);
            entries.set(path, payload);

            // A `while` rather than an `if`: `limit` can be lowered by a caller between
            // writes, and one `delete` would then leave the map permanently over cap.
            while (entries.size > limit) {
                entries.delete(entries.keys().next().value);
            }
        },

        /**
         * The payload to restore this path with, or null to fetch it.
         *
         * Returns a payload only when the navigation in progress is a restoration visit,
         * so a caller cannot serve a cached page for a link click by mistake — the check
         * and the lookup are one operation rather than two that have to be used together.
         *
         * The pop flag is consumed either way. This is called exactly once per navigation,
         * and a restoration visit that misses the cache has still been handled: it goes on
         * to fetch, and the flag must not survive into the navigation after it.
         *
         * The entry is *not* removed on a hit, which is where this differs from
         * `prefetcher.take()`. A prefetch is spent by the navigation it was made for; a
         * restoration entry has to answer every future visit back to that page.
         */
        take(path) {
            const wasRestoring = restoring;
            restoring = false;

            if (!enabled || !wasRestoring) {
                return null;
            }

            const payload = entries.get(path);

            if (payload === undefined) {
                return null;
            }

            // Refresh recency. Going back to a page is a use of it, so it should outlive
            // pages that have only been passed through once.
            entries.delete(path);
            entries.set(path, payload);

            return payload;
        },

        /** Drop one path, for an application that has just made it stale. */
        forget(path) {
            entries.delete(path);
        },

        /** Drop everything — a sign-out, or any change that invalidates every page. */
        clear() {
            entries.clear();
        },

        /** How many pages are held. Used by tests and by anything reporting on memory. */
        get size() {
            return entries.size;
        },

        /** Whether this instance does anything at all. */
        get enabled() {
            return enabled && limit > 0;
        },

        /**
         * Stop listening and drop everything.
         *
         * Called on reboot as well as by tests: `boot()` builds a new instance, and the
         * old one's `popstate` listener would otherwise stay attached to `window` for the
         * life of the document.
         */
        stop() {
            target.removeEventListener('popstate', onPopState);
            entries.clear();
        },
    };
}
