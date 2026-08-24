/**
 * The worker's half of push and background sync.
 *
 * Both are registered unconditionally. A `push` listener that never receives a push costs
 * nothing, and gating registration on config would mean push cannot start working until
 * the deploy *after* the one that enabled it.
 */
import { describe, expect, it } from 'vitest';
import { FakeCaches, createWorker } from './helpers/serviceWorkerHarness.js';

const manifest = (overrides = {}) => ({
    version: 'v1',
    cachePrefix: 'pwax',
    strategy: 'network-only',
    maxEntries: 60,
    navigationPreload: false,
    navigationStrategy: 'network-first',
    navigationUrls: [],
    shellUrl: '/__pwax__/shell',
    assetPrefixes: [],
    pageHeaders: {},
    assetGroups: [],
    hashTable: {},
    crossOrigin: [],
    critical: [],
    hash: 'h1',
    ...overrides,
});

const routes = () => new Response('ok');

function worker(options = {}) {
    return createWorker({ manifest: manifest(options.manifest), routes, ...options });
}

describe('push messages', () => {
    it('shows a notification from the payload', async () => {
        const w = worker();

        await w.dispatch('push', { data: { json: () => ({ title: 'Hello', body: 'World' }) } });

        expect(w.notifications).toEqual([
            expect.objectContaining({ title: 'Hello', body: 'World' }),
        ]);
    });

    it('shows something for a payload that is not JSON', async () => {
        const w = worker();

        await w.dispatch('push', {
            data: {
                json: () => {
                    throw new Error('not json');
                },
                text: () => 'plain words',
            },
        });

        // Every browser that implements push requires a notification per message, so
        // failing to show one is how an origin loses its push permission.
        expect(w.notifications[0].body).toBe('plain words');
    });

    it('falls back to the configured title', async () => {
        const w = worker({ manifest: { push: { title: 'Acme' } } });

        await w.dispatch('push', { data: { json: () => ({ body: 'Something happened' }) } });

        expect(w.notifications[0].title).toBe('Acme');
    });

    it('shows nothing at all for an empty push with no fallback', async () => {
        const w = worker();

        await w.dispatch('push', { data: { json: () => ({}) } });

        expect(w.notifications).toHaveLength(0);
    });
});

describe('clicking a notification', () => {
    const notification = (url) => ({
        notification: { close: () => {}, data: { url } },
    });

    it('focuses a window already on the target', async () => {
        let focused = false;
        const w = worker({
            clients: [{ url: 'https://app.test/inbox', focus: () => (focused = true) }],
        });

        await w.dispatch('notificationclick', notification('/inbox'));

        // Opening a second tab on a URL the app already has open is the thing people
        // notice and dislike.
        expect(focused).toBe(true);
        expect(w.windows).toHaveLength(0);
    });

    it('navigates an existing window when none is on the target', async () => {
        const visited = [];
        const w = worker({
            clients: [
                {
                    url: 'https://app.test/other',
                    focus: () => {},
                    navigate: async (url) => visited.push(url),
                },
            ],
        });

        await w.dispatch('notificationclick', notification('/inbox'));

        expect(visited).toEqual(['https://app.test/inbox']);
    });

    it('opens one when the app is closed', async () => {
        const w = worker({ clients: [] });

        await w.dispatch('notificationclick', notification('/inbox'));

        expect(w.windows).toEqual([{ opened: 'https://app.test/inbox' }]);
    });
});

describe('replaying queued writes', () => {
    async function queued(caches, body, headers = {}) {
        const cache = await caches.open('pwax-sync');

        await cache.put(
            `/__pwax__/sync/${Math.random()}`,
            new Response(
                JSON.stringify({ url: 'https://app.test/notes', method: 'POST', headers, body })
            )
        );
    }

    /** A page that answers the worker's request for the session's current CSRF token. */
    function tokenClient(token) {
        return {
            url: 'https://app.test/',
            postMessage: (message, transfer) => {
                if (message?.type === 'PWAX_SYNC_TOKEN') {
                    transfer[0].postMessage({ type: 'PWAX_SYNC_TOKEN', token });
                }
            },
        };
    }

    it('registers a background sync when one is available', async () => {
        const w = worker();

        await w.dispatch('message', { data: { type: 'PWAX_SYNC_REGISTER' } });

        expect(w.syncTags).toEqual(['pwax-sync']);
    });

    it('sends what is queued and empties it', async () => {
        const caches = new FakeCaches();
        await queued(caches, '{"text":"one"}');

        const sent = [];
        const w = createWorker({
            manifest: manifest(),
            caches,
            // No Background Sync here — Safari and Firefox both — so the worker replays
            // immediately instead, which is what a page that just queued something wants.
            backgroundSync: false,
            routes: (path) => {
                sent.push(path);

                return new Response('{}');
            },
        });

        await w.dispatch('message', { data: { type: 'PWAX_SYNC_REGISTER' } });

        expect(sent).toEqual(['/notes']);
        await expect((await caches.open('pwax-sync')).keys()).resolves.toHaveLength(0);
    });

    it('keeps a write the origin could not answer', async () => {
        const caches = new FakeCaches();
        await queued(caches, '{"text":"one"}');

        const w = createWorker({
            manifest: manifest(),
            caches,
            backgroundSync: false,
            routes: () => null,
        });

        await w.dispatch('message', { data: { type: 'PWAX_SYNC_REGISTER' } });

        await expect((await caches.open('pwax-sync')).keys()).resolves.toHaveLength(1);
    });

    it('drops a write the server refused', async () => {
        const caches = new FakeCaches();
        await queued(caches, '{"text":"one"}');

        const w = createWorker({
            manifest: manifest(),
            caches,
            backgroundSync: false,
            routes: () => new Response('nope', { status: 422 }),
        });

        await w.dispatch('message', { data: { type: 'PWAX_SYNC_REGISTER' } });

        // The server said no. Saying it again tomorrow will not change that, and retrying
        // forever is how a queue stops draining.
        await expect((await caches.open('pwax-sync')).keys()).resolves.toHaveLength(0);
    });

    // 419 is the status this queue is most likely to meet and the one it must never treat
    // as an answer. An entry carries the CSRF token that was current when it was queued,
    // so a write that sat offline longer than the session lifetime comes back 419 on its
    // first replay — every time, by construction. Dropping it there deleted exactly the
    // writes the feature exists to protect, silently.
    it.each([
        [419, 'an expired session or CSRF token'],
        [408, 'a request timeout'],
        [425, 'too early'],
        [429, 'a rate limit'],
    ])('keeps a write refused with %i (%s)', async (status) => {
        const caches = new FakeCaches();
        await queued(caches, '{"text":"one"}');

        const w = createWorker({
            manifest: manifest(),
            caches,
            backgroundSync: false,
            routes: () => new Response('nope', { status }),
        });

        await w.dispatch('message', { data: { type: 'PWAX_SYNC_REGISTER' } });

        await expect((await caches.open('pwax-sync')).keys()).resolves.toHaveLength(1);
    });

    /**
     * The 419 story only works if the token moves.
     *
     * An entry carries the CSRF token that was current when it was queued, and `RETRYABLE`
     * deliberately keeps 419 out of the "the server answered" set so a long-queued write is
     * not deleted. But the replay used to re-send the *stored* headers, so the retry
     * presented the same dead token and got the same 419 — for ever. The entry was
     * immortal, the write never landed, and the "3 changes will send" counter never moved.
     */
    it('replays with the token the page has now, not the one it was queued with', async () => {
        const caches = new FakeCaches();
        await queued(caches, '{"text":"one"}', { 'X-CSRF-TOKEN': 'the-dead-one' });

        const w = createWorker({
            manifest: manifest(),
            caches,
            backgroundSync: false,
            clients: [tokenClient('the-current-one')],
            routes: () => new Response('{}'),
        });

        await w.dispatch('message', { data: { type: 'PWAX_SYNC_REGISTER' } });

        const replayed = w.requests.at(-1);

        expect(replayed.headers.get('X-CSRF-TOKEN')).toBe('the-current-one');
        await expect((await caches.open('pwax-sync')).keys()).resolves.toHaveLength(0);
    });

    it('sends the stored token when no page is open to ask', async () => {
        const caches = new FakeCaches();
        await queued(caches, '{"text":"one"}', { 'X-CSRF-TOKEN': 'the-stored-one' });

        // A genuine Background Sync wake with every tab closed. The stored token is all
        // there is, which is where this started — so nothing is worse than before.
        const w = createWorker({
            manifest: manifest(),
            caches,
            backgroundSync: false,
            clients: [],
            routes: () => new Response('{}'),
        });

        await w.dispatch('message', { data: { type: 'PWAX_SYNC_REGISTER' } });

        expect(w.requests.at(-1).headers.get('X-CSRF-TOKEN')).toBe('the-stored-one');
    });

    it('does not add a token to an entry that never had one', async () => {
        const caches = new FakeCaches();
        await queued(caches, '{"text":"one"}', { 'Content-Type': 'application/json' });

        const w = createWorker({
            manifest: manifest(),
            caches,
            backgroundSync: false,
            clients: [tokenClient('the-current-one')],
            routes: () => new Response('{}'),
        });

        await w.dispatch('message', { data: { type: 'PWAX_SYNC_REGISTER' } });

        // Replaced, never added. An entry queued by a session that had no token is a
        // request the application meant to send without one.
        expect(w.requests.at(-1).headers.get('X-CSRF-TOKEN')).toBeNull();
    });

    it('asks one page for the token however many writes are waiting', async () => {
        const caches = new FakeCaches();

        for (let i = 0; i < 5; i++) {
            await queued(caches, `{"text":"${i}"}`, { 'X-CSRF-TOKEN': 'old' });
        }

        let asked = 0;
        const client = tokenClient('fresh');
        const counted = {
            ...client,
            postMessage: (message, transfer) => {
                asked++;
                client.postMessage(message, transfer);
            },
        };

        const w = createWorker({
            manifest: manifest(),
            caches,
            backgroundSync: false,
            clients: [counted],
            routes: () => new Response('{}'),
        });

        await w.dispatch('message', { data: { type: 'PWAX_SYNC_REGISTER' } });

        // Every entry in the queue belongs to the same session. Five round trips to a page
        // for one answer is five chances for the drain to stall behind a busy tab.
        expect(asked).toBe(1);
    });

    it('drops an entry it cannot read rather than blocking the queue behind it', async () => {
        const caches = new FakeCaches();
        const cache = await caches.open('pwax-sync');

        await cache.put('/__pwax__/sync/corrupt', new Response('not json at all'));
        await queued(caches, '{"text":"one"}');

        const sent = [];
        const w = createWorker({
            manifest: manifest(),
            caches,
            backgroundSync: false,
            routes: (path) => {
                sent.push(path);

                return new Response('{}');
            },
        });

        await w.dispatch('message', { data: { type: 'PWAX_SYNC_REGISTER' } });

        // The readable entry behind it still went. Left in place, the unreadable one would
        // throw out of `replay()` on every sync and nothing after it would ever send.
        expect(sent).toEqual(['/notes']);
        await expect(cache.keys()).resolves.toHaveLength(0);
    });
});
