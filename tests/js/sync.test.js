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

    beforeEach(() => {
        caches = new FakeCaches();
        window.caches = caches;
        posted = [];
        navigator.serviceWorker = {
            controller: { postMessage: (message) => posted.push(message) },
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
