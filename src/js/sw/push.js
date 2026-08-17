/**
 * Push messages, notification clicks, and replaying work queued while offline.
 *
 * All three are worker-side halves of APIs whose page-side half lives elsewhere. They are
 * registered unconditionally but do nothing unless configured — a `push` listener that
 * never receives a push costs nothing, and gating registration behind config would mean a
 * worker that cannot start receiving push until the *next* deploy after you enable it.
 */

/**
 * A push message, rendered as a notification.
 *
 * The payload shape is the Notification API's own, so anything
 * `laravel-notification-channels/webpush` sends works without translation: `title`, `body`,
 * `icon`, `badge`, `tag`, `data`, `actions`, and `data.url` for where a click should go.
 *
 * A push that arrives with no payload, or with something that is not JSON, still shows
 * something. Every browser that implements push requires `userVisibleOnly`, so failing to
 * show a notification is how an origin loses its push permission — a generic message is a
 * far better outcome than silence.
 */
export function registerPush(config) {
    self.addEventListener('push', (event) => {
        const defaults = config.push || {};

        let payload = {};

        try {
            payload = event.data ? event.data.json() : {};
        } catch {
            payload = { body: event.data ? event.data.text() : '' };
        }

        const title = payload.title || defaults.title || '';

        if (!title && !payload.body) {
            return;
        }

        event.waitUntil(
            self.registration.showNotification(title, {
                body: payload.body || '',
                icon: payload.icon || defaults.icon || undefined,
                badge: payload.badge || defaults.badge || undefined,
                tag: payload.tag || undefined,
                data: payload.data || {},
                actions: payload.actions || [],
                requireInteraction: payload.requireInteraction === true,
            })
        );
    });

    /**
     * A click focuses an open window on the target if there is one, and opens one if not.
     *
     * Opening a second tab on a URL the app already has open is the thing users notice and
     * dislike, and it is what happens if you call `openWindow` unconditionally.
     */
    self.addEventListener('notificationclick', (event) => {
        event.notification.close();

        const target = new URL(
            (event.notification.data && event.notification.data.url) || '/',
            self.location.origin
        ).href;

        event.waitUntil(
            (async () => {
                const clients = await self.clients.matchAll({
                    type: 'window',
                    includeUncontrolled: true,
                });

                for (const client of clients) {
                    if (client.url === target && 'focus' in client) {
                        return client.focus();
                    }
                }

                for (const client of clients) {
                    if ('navigate' in client && 'focus' in client) {
                        await client.navigate(target);

                        return client.focus();
                    }
                }

                return self.clients.openWindow(target);
            })()
        );
    });
}

/**
 * 4xx statuses that mean "not now", not "no".
 *
 * The one that matters is 419. Laravel returns it for an expired session or CSRF token,
 * and an entry in this queue carries the token that was current when it was queued — so
 * a write that sat offline longer than `session.lifetime` is *guaranteed* to come back
 * 419 on its first replay. Treating that as a real answer deleted exactly the writes this
 * feature exists to protect: the ones queued for a long time, silently, with no way for
 * the application to find out. The next replay happens from a page that has since
 * refreshed the session, which is the one that succeeds.
 *
 * 408 and 425 are the server asking for the request again in as many words. 429 is a rate
 * limit, which is temporary by definition — and dropping a queued write because the
 * device came back online into a burst of them would be the worst possible reading of it.
 */
const RETRYABLE = new Set([408, 419, 425, 429]);

/**
 * Requests queued while offline, replayed when the connection returns.
 *
 * The queue is a cache rather than IndexedDB, deliberately: the worker already depends on
 * the Cache API and nothing else, a queued request *is* a Request, and Cache Storage
 * stores those natively with their method, headers and body intact. An IndexedDB layer
 * would mean serialising and rebuilding all three by hand for no gain.
 *
 * A replayed request that fails with a real answer — a 4xx — is dropped rather than
 * retried forever. The server said no; saying it again tomorrow will not change that.
 * `RETRYABLE` above is the list of 4xx statuses for which that reasoning does not hold.
 */
export function registerSync(config, cacheName) {
    const TAG = 'pwax-sync';

    const queue = () => caches.open(cacheName);

    self.addEventListener('sync', (event) => {
        if (event.tag !== TAG) {
            return;
        }

        event.waitUntil(replay());
    });

    self.addEventListener('message', (event) => {
        if (!event.data || event.data.type !== 'PWAX_SYNC_REGISTER') {
            return;
        }

        event.waitUntil(
            (async () => {
                try {
                    await self.registration.sync.register(TAG);
                } catch {
                    // No Background Sync here — Safari and Firefox both. Replay now
                    // instead, which is what a page that just queued something wants
                    // anyway if it turns out to be online.
                    await replay();
                }
            })()
        );
    });

    async function replay() {
        if (!(await caches.has(cacheName))) {
            return;
        }

        const cache = await queue();

        for (const key of await cache.keys()) {
            const stored = await cache.match(key);

            if (!stored) {
                continue;
            }

            let queued;

            try {
                queued = await stored.json();
            } catch {
                // Not a queue entry this version of the worker can read — a truncated
                // write, or one left by a build whose format has since changed. Dropped
                // rather than skipped: left in place it would be re-read, and re-fail, on
                // every sync forever, and `replay()` walks the queue in order, so one
                // unreadable entry would outlive every application it blocked.
                await cache.delete(key);

                continue;
            }

            const { url, method, headers, body } = queued;

            try {
                const response = await fetch(url, {
                    method,
                    headers,
                    body: body ?? undefined,
                    credentials: 'same-origin',
                });

                // Kept only while the origin cannot answer. A 4xx is an answer — except
                // for the handful that explicitly are not; see `RETRYABLE` above.
                const answered =
                    response.status >= 400 &&
                    response.status < 500 &&
                    !RETRYABLE.has(response.status);

                if (response.ok || answered) {
                    await cache.delete(key);
                }
            } catch {
                // Still offline. Leave it for the next sync.
                return;
            }
        }
    }

    // Exposed so the page can ask what is waiting, which is the only way to build a
    // "3 changes will send when you are back online" affordance.
    self.addEventListener('message', (event) => {
        if (!event.data || event.data.type !== 'PWAX_SYNC_PENDING') {
            return;
        }

        event.waitUntil(
            (async () => {
                const count = (await caches.has(cacheName))
                    ? (await (await queue()).keys()).length
                    : 0;

                const port = event.ports && event.ports[0];

                if (port) {
                    port.postMessage({ type: 'PWAX_SYNC_PENDING', count });
                }
            })()
        );
    });

    return { replay };
}
