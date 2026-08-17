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

    /**
     * Is the configured endpoint on this origin?
     *
     * It has to be, and the check is not a formality. `http.headers()` carries this
     * session's CSRF token, and posting those headers to a URL somebody put in
     * `pwax.push.endpoint` — a typo, a copied example, a compromised config — would hand
     * that token to another origin. A subscription is not secret; the token is.
     */
    const isSameOrigin = (endpoint) => {
        try {
            return new URL(endpoint, window.location.origin).origin === window.location.origin;
        } catch {
            return false;
        }
    };

    /**
     * Tell the application's endpoint about a subscription, or its removal.
     *
     * A failure here is not cosmetic and is not swallowed. The browser is now subscribed
     * and the server does not know it exists, so every push the application believes it
     * sent goes nowhere — and the only symptom is notifications that never arrive, which
     * is indistinguishable from a VAPID problem, a permission problem, or a bug in the
     * sending code. Saying so at the point of failure is the difference between a
     * five-minute fix and an afternoon.
     */
    const report = async (subscription, method) => {
        if (!settings.endpoint) {
            return;
        }

        if (!isSameOrigin(settings.endpoint)) {
            console.error(
                `pwax: pwax.push.endpoint must be on this origin, got "${settings.endpoint}". ` +
                    "The subscription was not reported — sending it would leak this session's " +
                    'CSRF token to another origin.'
            );

            return;
        }

        let response;

        try {
            response = await fetch(settings.endpoint, {
                method,
                credentials: 'same-origin',
                headers: { ...http.headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify(subscription),
            });
        } catch (error) {
            console.error(
                `pwax: could not reach ${settings.endpoint} to ${method} a push subscription.`,
                error
            );

            return;
        }

        if (!response.ok) {
            console.error(
                `pwax: ${settings.endpoint} answered ${response.status} to a push subscription ` +
                    `${method}. The subscription exists in the browser but not on the server, so ` +
                    'no push sent to it will arrive.'
            );
        }
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
