/**
 * Web Push, from the page's side.
 *
 * The package owns the browser half only. Storing subscriptions and sending to them is
 * `laravel-notification-channels/webpush`'s job; what has to be right here is the key
 * conversion, the gesture requirement, and telling the application's endpoint before
 * dropping a subscription rather than after.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPushApi, decodeKey } from '../../src/js/push.js';

const KEY =
    'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U';

const http = { headers: () => ({ 'X-CSRF-TOKEN': 'token' }) };

function pushManager({ existing = null } = {}) {
    return {
        getSubscription: vi.fn(async () => existing),
        subscribe: vi.fn(async (options) => ({
            endpoint: 'https://push.test/abc',
            options,
            unsubscribe: vi.fn(async () => true),
            toJSON: () => ({ endpoint: 'https://push.test/abc' }),
        })),
    };
}

function install({ manager = pushManager(), permission = 'granted' } = {}) {
    navigator.serviceWorker = { ready: Promise.resolve({ pushManager: manager }) };
    window.PushManager = function PushManagerStub() {};
    window.Notification = { permission, requestPermission: vi.fn(async () => permission) };
    global.Notification = window.Notification;

    return manager;
}

describe('the VAPID key', () => {
    it('decodes base64url to the bytes subscribe() wants', () => {
        const bytes = decodeKey(KEY);

        // 65 bytes, uncompressed P-256 point, always starting 0x04. Every Web Push
        // tutorial opens with this conversion, which is a good sign it belongs in a
        // library rather than in every application.
        expect(bytes).toBeInstanceOf(Uint8Array);
        expect(bytes).toHaveLength(65);
        expect(bytes[0]).toBe(4);
    });
});

describe('subscribing', () => {
    beforeEach(() => {
        global.fetch = vi.fn(async () => new Response('{}'));
    });

    afterEach(() => {
        delete navigator.serviceWorker;
        delete window.PushManager;
        delete window.Notification;
        delete global.Notification;
    });

    it('reports honestly when push is unavailable', async () => {
        const api = createPushApi({ publicKey: KEY }, http);

        expect(api.supported).toBe(false);
        await expect(api.subscribe()).resolves.toBeNull();
    });

    it('does nothing without a configured key', async () => {
        const manager = install();

        await expect(createPushApi({}, http).subscribe()).resolves.toBeNull();
        expect(manager.subscribe).not.toHaveBeenCalled();
    });

    it('asks permission, subscribes, and posts the subscription', async () => {
        const manager = install();

        const subscription = await createPushApi(
            { publicKey: KEY, endpoint: '/push/subscribe' },
            http
        ).subscribe();

        expect(subscription).not.toBeNull();
        expect(Notification.requestPermission).toHaveBeenCalled();
        expect(manager.subscribe.mock.calls[0][0].userVisibleOnly).toBe(true);

        const [url, init] = global.fetch.mock.calls[0];

        expect(url).toBe('/push/subscribe');
        expect(init.method).toBe('POST');
        // The application's endpoint is a route of theirs, so it is behind `web` and
        // needs the token like any other write.
        expect(init.headers['X-CSRF-TOKEN']).toBe('token');
        expect(JSON.parse(init.body).endpoint).toBe('https://push.test/abc');
    });

    it('stops at a refused permission', async () => {
        const manager = install({ permission: 'denied' });

        await expect(
            createPushApi({ publicKey: KEY, endpoint: '/push' }, http).subscribe()
        ).resolves.toBeNull();

        expect(manager.subscribe).not.toHaveBeenCalled();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('returns an existing subscription rather than replacing it', async () => {
        const existing = { endpoint: 'https://push.test/old' };
        const manager = install({ manager: pushManager({ existing }) });

        await expect(createPushApi({ publicKey: KEY }, http).subscribe()).resolves.toBe(existing);

        // Resubscribing with the same key returns the same endpoint anyway, and doing it
        // silently would hide a key change that ought to be noticed.
        expect(manager.subscribe).not.toHaveBeenCalled();
    });
});

describe('unsubscribing', () => {
    beforeEach(() => {
        global.fetch = vi.fn(async () => new Response('{}'));
    });

    afterEach(() => {
        delete navigator.serviceWorker;
        delete window.PushManager;
        delete window.Notification;
        delete global.Notification;
    });

    it('tells the endpoint before dropping the subscription', async () => {
        const order = [];
        const existing = {
            endpoint: 'https://push.test/abc',
            unsubscribe: vi.fn(async () => {
                order.push('unsubscribe');

                return true;
            }),
            toJSON: () => ({ endpoint: 'https://push.test/abc' }),
        };

        global.fetch = vi.fn(async () => {
            order.push('report');

            return new Response('{}');
        });

        install({ manager: pushManager({ existing }) });

        await createPushApi({ publicKey: KEY, endpoint: '/push' }, http).unsubscribe();

        // The other order leaves the server sending to a subscription that is gone until
        // the push service starts returning 410, which it does at its own pace.
        expect(order).toEqual(['report', 'unsubscribe']);
        expect(global.fetch.mock.calls[0][1].method).toBe('DELETE');
    });

    it('is a no-op with nothing subscribed', async () => {
        install();

        await expect(
            createPushApi({ publicKey: KEY, endpoint: '/push' }, http).unsubscribe()
        ).resolves.toBe(false);

        expect(global.fetch).not.toHaveBeenCalled();
    });
});

describe('reporting a subscription to the application', () => {
    beforeEach(() => {
        global.fetch = vi.fn(async () => new Response('{}'));
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        delete navigator.serviceWorker;
        delete window.PushManager;
        delete window.Notification;
        delete global.Notification;
    });

    it('refuses an endpoint on another origin', async () => {
        install();

        const subscription = await createPushApi(
            { publicKey: KEY, endpoint: 'https://elsewhere.test/push' },
            http
        ).subscribe();

        // `http.headers()` carries this session's CSRF token. A typo or a copied example
        // in `pwax.push.endpoint` must not be able to post it to another origin.
        expect(subscription).not.toBeNull();
        expect(global.fetch).not.toHaveBeenCalled();
        expect(console.error).toHaveBeenCalledWith(
            expect.stringContaining('must be on this origin')
        );
    });

    it('says so when the endpoint rejects the subscription', async () => {
        install();
        global.fetch = vi.fn(async () => new Response('nope', { status: 500 }));

        await createPushApi({ publicKey: KEY, endpoint: '/push' }, http).subscribe();

        // The browser is subscribed and the server does not know it exists, so every push
        // the application believes it sent goes nowhere. Silence here is an afternoon of
        // debugging VAPID keys for a problem that is not in them.
        expect(console.error).toHaveBeenCalledWith(expect.stringContaining('answered 500'));
    });

    it('says so when the endpoint cannot be reached at all', async () => {
        install();
        global.fetch = vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        });

        await expect(
            createPushApi({ publicKey: KEY, endpoint: '/push' }, http).subscribe()
        ).resolves.not.toBeNull();

        expect(console.error).toHaveBeenCalledWith(
            expect.stringContaining('could not reach /push'),
            expect.any(TypeError)
        );
    });
});
