/**
 * Queuing a write for when the connection comes back.
 *
 * An offline-capable app that can only *read* offline is half an app. Nothing is queued
 * automatically, deliberately: intercepting failed writes would replay a payment as
 * readily as a draft, and only the application knows which of its requests repeat safely.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FakeCaches } from './helpers/serviceWorkerHarness.js';
import { createSyncApi } from '../../src/js/sync.js';

const http = { headers: () => ({ 'X-CSRF-TOKEN': 'token' }) };

describe('the offline queue', () => {
    let caches;
    let posted;
    let listeners;

    beforeEach(() => {
        caches = new FakeCaches();
        window.caches = caches;
        posted = [];
        listeners = {};
        navigator.serviceWorker = {
            controller: { postMessage: (message) => posted.push(message) },
            addEventListener: (type, handler) => {
                (listeners[type] ||= []).push(handler);
            },
        };
    });

    afterEach(() => {
        delete window.caches;
        delete navigator.serviceWorker;
    });

    it('stores a request and asks the worker to send it', async () => {
        const sync = createSyncApi({ cachePrefix: 'pwax' }, http);

        await expect(sync.enqueue('/notes', { body: { text: 'hi' } })).resolves.toBe(true);

        const cache = await caches.open('pwax-sync');
        const [key] = await cache.keys();
        const stored = await (await cache.match(key)).json();

        expect(stored.method).toBe('POST');
        expect(stored.url).toContain('/notes');
        expect(stored.headers['X-CSRF-TOKEN']).toBe('token');
        expect(JSON.parse(stored.body)).toEqual({ text: 'hi' });

        expect(posted).toEqual([{ type: 'PWAX_SYNC_REGISTER' }]);
    });

    it('refuses a cross-origin URL rather than queueing the CSRF token for it', async () => {
        const sync = createSyncApi({ cachePrefix: 'pwax' }, http);
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});

        // The stored headers carry this session's CSRF token and the worker replays them
        // verbatim, from a context the page cannot see. `push.js` refuses the same thing
        // for `pwax.push.endpoint`; this had no such guard, so a typo — or a third-party
        // API somebody meant to call — handed the token away.
        await expect(sync.enqueue('https://elsewhere.test/collect')).resolves.toBe(false);

        expect(error).toHaveBeenCalled();
        await expect(sync.pending()).resolves.toBe(0);

        error.mockRestore();
    });

    it('still accepts a relative URL', async () => {
        const sync = createSyncApi({ cachePrefix: 'pwax' }, http);

        await expect(sync.enqueue('/notes')).resolves.toBe(true);
        await expect(sync.enqueue(`${window.location.origin}/notes`)).resolves.toBe(true);
    });

    it('tells the worker the token this document has now', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="the-current-one">';

        createSyncApi({ cachePrefix: 'pwax', csrf: 'the-boot-time-one' }, http);

        const replies = [];
        const port = { postMessage: (message) => replies.push(message) };

        for (const handler of listeners.message || []) {
            handler({ data: { type: 'PWAX_SYNC_TOKEN' }, ports: [port] });
        }

        // The meta tag, not `config.csrf`. A login redirects and the new document carries
        // the new token; `config.csrf` is whatever was current when the runtime started,
        // which is the value that produced the 419 in the first place.
        expect(replies).toEqual([{ type: 'PWAX_SYNC_TOKEN', token: 'the-current-one' }]);

        document.head.innerHTML = '';
    });

    it('falls back to the boot-time token when the document has no meta tag', async () => {
        createSyncApi({ cachePrefix: 'pwax', csrf: 'the-boot-time-one' }, http);

        const replies = [];

        for (const handler of listeners.message || []) {
            handler({
                data: { type: 'PWAX_SYNC_TOKEN' },
                ports: [{ postMessage: (m) => replies.push(m) }],
            });
        }

        expect(replies[0].token).toBe('the-boot-time-one');
    });

    it('survives a serviceWorker stand-in that is not an event target', async () => {
        navigator.serviceWorker = { controller: null };

        // Guarded on the method rather than the property: throwing here would take the
        // whole queue API down for a browser quirk that has nothing to do with queuing.
        expect(() => createSyncApi({ cachePrefix: 'pwax' }, http)).not.toThrow();
    });

    it('keeps two writes apart', async () => {
        const sync = createSyncApi({ cachePrefix: 'pwax' }, http);

        await sync.enqueue('/notes', { body: { text: 'one' } });
        await sync.enqueue('/notes', { body: { text: 'two' } });

        // Same URL, same method. Keyed on the URL alone the second would overwrite the
        // first and someone would lose what they typed.
        await expect(sync.pending()).resolves.toBe(2);
    });

    it('announces what it queued', async () => {
        const heard = vi.fn();
        document.addEventListener('pwax:queued', heard, { once: true });

        await createSyncApi({ cachePrefix: 'pwax' }, http).enqueue('/notes');

        expect(heard).toHaveBeenCalled();
    });

    it('reports nothing pending before anything is queued', async () => {
        // Must answer before a worker controls the page — which is exactly when someone
        // reloads and wants to know their draft is still going to send.
        await expect(createSyncApi({ cachePrefix: 'pwax' }, http).pending()).resolves.toBe(0);
    });

    it('says so rather than silently dropping a write', async () => {
        delete window.caches;

        // A caller that gets `false` can fail loudly. One that gets `true` and lost the
        // request cannot.
        await expect(createSyncApi({ cachePrefix: 'pwax' }, http).enqueue('/notes')).resolves.toBe(
            false
        );
    });

    it('uses the configured cache prefix', async () => {
        await createSyncApi({ cachePrefix: 'acme' }, http).enqueue('/notes');

        expect(await caches.keys()).toContain('acme-sync');
    });
});
