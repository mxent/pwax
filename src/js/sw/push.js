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
 * the application to find out.
 *
 * Keeping the entry is only half of it, and for a while it was the only half: the replay
 * re-sent the stored headers, so the retry presented the same dead token and got the same
 * 419, for ever. `freshToken()` below is what makes the retry a different request from the
 * one that failed — the token is taken from an open page at replay time, so the next
 * attempt carries the session that page is actually on.
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

    // How long to wait for a page to say what this session's CSRF token is. Short, because
    // the fallback is the token already stored with the entry and the queue must not stall
    // behind a tab that is not listening.
    const TOKEN_TIMEOUT = 1000;

    const queue = () => caches.open(cacheName);

    /**
     * This session's current CSRF token, asked of an open page.
     *
     * The token stored with a queued write is the one that was current when it was queued.
     * That is exactly the token a long-queued write comes back 419 for — and replaying the
     * stored headers verbatim meant the retry sent the same dead token again, so an entry
     * that 419'd once 419'd forever. `RETRYABLE` keeping 419 out of the "answered" set is
     * only half the fix; this is the other half, and without it the entry is immortal and
     * the "3 changes will send" counter never goes down.
     *
     * Null when no page answers — a genuine Background Sync wake with every tab closed.
     * The stored token is then all there is, which is where this started, so nothing is
     * worse than before.
     */
    async function freshToken() {
        let clients = [];

        try {
            clients = await self.clients.matchAll({ type: 'window' });
        } catch {
            return null;
        }

        for (const client of clients) {
            const token = await new Promise((resolve) => {
                const channel = new MessageChannel();
                const done = setTimeout(() => resolve(null), TOKEN_TIMEOUT);

                channel.port1.onmessage = (event) => {
                    clearTimeout(done);
                    resolve(event.data?.token || null);
                };

                try {
                    client.postMessage({ type: 'PWAX_SYNC_TOKEN' }, [channel.port2]);
                } catch {
                    clearTimeout(done);
                    resolve(null);
                }
            });

            if (token) {
                return token;
            }
        }

        return null;
    }

    /**
     * The stored headers, with a stale CSRF token swapped for the current one.
     *
     * Only replaced, never added: an entry queued by a session that had no token gets none
     * now either, and a caller who set their own header keeps it. The comparison is
     * case-insensitive because the headers came from a plain object somebody may have
     * spelled differently.
     */
    function withToken(headers, token) {
        if (!token || !headers) {
            return headers;
        }

        const updated = { ...headers };
        let replaced = false;

        for (const name of Object.keys(updated)) {
            if (name.toLowerCase() === 'x-csrf-token') {
                updated[name] = token;
                replaced = true;
            }
        }

        return replaced ? updated : headers;
    }

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

        const keys = await cache.keys();

        if (keys.length === 0) {
            return;
        }

        // Asked once for the whole drain rather than per entry: every entry in the queue
        // belongs to the same session, and a round trip to a page for each of fifty queued
        // writes is fifty round trips for one answer.
        const token = await freshToken();

        for (const key of keys) {
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
                    headers: withToken(headers, token),
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
