/**
 * Web Push subscription, from the page's side.
 *
 * The package handles the browser half — asking permission, subscribing with your VAPID
 * key, handing the resulting subscription to your endpoint, and receiving the message in
 * the worker. It deliberately does not handle the server half. Storing subscriptions and
 * sending to them is `laravel-notification-channels/webpush`'s job, it does it well, and
 * a second implementation inside a PWA package would be a worse one.
 *
 * So: configure `pwax.push.public_key` and an endpoint that persists what this posts to
 * it, and the rest is here.
 */

/**
 * A VAPID public key as the `applicationServerKey` option wants it.
 *
 * The key is distributed base64url-encoded and `pushManager.subscribe` takes bytes. This
 * is the conversion every Web Push tutorial opens with, which is a good sign it belongs
 * in a library rather than in every application.
 */
export function decodeKey(base64) {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const raw = window.atob(padded);
    const bytes = new Uint8Array(raw.length);

    for (let i = 0; i < raw.length; i += 1) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

/**
 * @param {{publicKey?: string|null, endpoint?: string|null}} config
 * @param {{ headers: (extra?: object) => object }} http
 */
export function createPushApi(config, http) {
    const settings = config || {};

    const registration = async () => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return null;
        }

        return navigator.serviceWorker.ready;
    };

    /** Tell the application's endpoint about a subscription, or its removal. */
    const report = async (subscription, method) => {
        if (!settings.endpoint) {
            return;
        }

        await fetch(settings.endpoint, {
            method,
            credentials: 'same-origin',
            headers: { ...http.headers(), 'Content-Type': 'application/json' },
            body: JSON.stringify(subscription),
        });
    };

    return {
        /** Is push usable here at all? */
        get supported() {
            return (
                'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
            );
        },

        /** `'granted'`, `'denied'`, `'default'`, or `'unsupported'`. */
        get permission() {
            return 'Notification' in window ? Notification.permission : 'unsupported';
        },

        /** The current subscription, or null. */
        async subscription() {
            const ready = await registration();

            return ready ? ready.pushManager.getSubscription() : null;
        },

        /**
         * Ask permission, subscribe, and post the subscription to your endpoint.
         *
         * Must be called from a user gesture. Browsers reject a permission request that
         * did not come from one, and a page that asks on load is the reason they do.
         *
         * Resolves to the `PushSubscription`, or null if permission was refused or push
         * is unavailable. An existing subscription is returned as-is rather than being
         * replaced — resubscribing with the same key returns the same endpoint anyway,
         * and doing it silently would hide a key change that should be noticed.
         */
        async subscribe() {
            const ready = await registration();

            if (!ready || !settings.publicKey) {
                return null;
            }

            const existing = await ready.pushManager.getSubscription();

            if (existing) {
                return existing;
            }

            if ((await Notification.requestPermission()) !== 'granted') {
                return null;
            }

            const subscription = await ready.pushManager.subscribe({
                // Required by every browser that implements push: a subscription that any
                // server could send to is one any server will.
                userVisibleOnly: true,
                applicationServerKey: decodeKey(settings.publicKey),
            });

            await report(subscription, 'POST');

            document.dispatchEvent(
                new CustomEvent('pwax:push-subscribed', { detail: { subscription } })
            );

            return subscription;
        },

        /**
         * Unsubscribe, and tell your endpoint to forget it.
         *
         * The endpoint is told first. A subscription dropped locally but left on the
         * server is one your application keeps sending to until the push service starts
         * returning 410, which it does at its own pace.
         */
        async unsubscribe() {
            const ready = await registration();
            const subscription = ready ? await ready.pushManager.getSubscription() : null;

            if (!subscription) {
                return false;
            }

            await report(subscription, 'DELETE');

            const gone = await subscription.unsubscribe();

            document.dispatchEvent(new CustomEvent('pwax:push-unsubscribed'));

            return gone;
        },
    };
}
