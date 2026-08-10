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
    async function queued(caches, body) {
        const cache = await caches.open('pwax-sync');

        await cache.put(
            `/__pwax__/sync/${Math.random()}`,
            new Response(
                JSON.stringify({ url: 'https://app.test/notes', method: 'POST', headers: {}, body })
            )
        );
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
});
