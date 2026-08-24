/**
 * Service worker registration and update handling.
 *
 * Registering is the easy half. The half that is usually missed is telling the user when
 * a new version is waiting: without it, a returning visitor keeps running the previous
 * build until they happen to close every tab. `pwax:update-available` fires with an
 * `activate()` callback so the application can offer a "reload to update" prompt.
 *
 * The other half that is usually got wrong is reloading on `controllerchange`. That event
 * fires whenever *any* worker takes control, including one that skipped waiting on its
 * own, so an unconditional reload there restarts every open tab on every deploy and
 * throws away whatever the user was typing. This reloads only when this page asked for
 * the update — which is the only case where the user is expecting it.
 */

/** Update checks after the first load. Hourly is often enough to matter, rarely enough
 *  to be invisible; the browser also checks on its own at most once a day. */
const UPDATE_INTERVAL = 60 * 60 * 1000;

/** Connectivity listeners belong to the page, not to a registration. */
let connectivityWatched = false;

/**
 * A build that has installed and is waiting for every tab to close, with the callback
 * that lets it through.
 *
 * Held here so `pwax.sw.applyUpdate()` can reach it. Without that, an application whose
 * developer has not wired up the `pwax:update-available` event has no way to take an
 * update short of closing every tab — and no way to tell that is what is happening, which
 * reads exactly like a deploy that did not deploy.
 */
let pendingUpdate = null;

export function registerServiceWorker(path, { scope = '/' } = {}) {
    // Truthiness, not `in`. Service workers require a secure context, and on a page
    // served over plain HTTP the property is present on the prototype but the value is
    // `undefined` — so an `in` check passes and the next line throws.
    if (!path || !navigator.serviceWorker) {
        return Promise.resolve(null);
    }

    return navigator.serviceWorker
        .register(path, { scope })
        .then((registration) => {
            watchForUpdates(registration);

            return registration;
        })
        .catch((error) => {
            console.warn('pwax: service worker registration failed', error);

            return null;
        });
}

function watchForUpdates(registration) {
    // True once *this page* has asked a waiting worker to take over. Scoped to the
    // registration rather than the module so that one page's decision cannot be read as
    // another's — it is the difference between an expected reload and a lost form.
    let activating = false;
    let reloading = false;
    let lastCheck = Date.now();

    const announce = (worker) => {
        const activate = () => {
            // Cleared as it is taken. Leaving it set would have a second call report
            // success against a worker that is already on its way to controlling the page.
            pendingUpdate = null;
            activating = true;
            worker.postMessage({ type: 'PWAX_SKIP_WAITING' });
        };

        pendingUpdate = { worker, activate };

        // Said out loud, because the alternative is silence. A new build does not take
        // over on its own — that would reload every open tab and discard whatever was
        // being typed — so it waits, and an application that does not listen for the event
        // below gives no sign at all. The symptom is a deploy that appears not to have
        // happened, and this is the one line that explains it.
        //
        // `info`, not `warn`: nothing is wrong. The lint rule allows only warn and error,
        // and logging this at either would report a deploy working as designed as a fault.
        // eslint-disable-next-line no-console
        console.info(
            'pwax: a new version is installed and waiting. It takes over when every tab of ' +
                'this app is closed, or immediately via pwax.sw.applyUpdate().'
        );

        document.dispatchEvent(new CustomEvent('pwax:update-available', { detail: { activate } }));
    };

    // A worker already waiting means an update arrived while the page was closed.
    if (registration.waiting && navigator.serviceWorker.controller) {
        announce(registration.waiting);
    }

    registration.addEventListener('updatefound', () => {
        const installing = registration.installing;

        if (!installing) {
            return;
        }

        installing.addEventListener('statechange', () => {
            // `controller` being set distinguishes an update from a first install.
            if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                announce(installing);
            }
        });
    });

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        // Only when this page asked for it. A worker that took control for some other
        // reason is not a reason to discard the user's unsaved work.
        if (reloading || !activating) {
            return;
        }

        reloading = true;
        window.location.reload();
    });

    // A tab left open for days would otherwise never learn that a new build exists.
    const check = () => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        lastCheck = Date.now();
        registration.update().catch(() => {});
    };

    // Kept, rather than left to `visibilitychange` alone: a dashboard on a wall-mounted
    // screen is visible for weeks and never fires one, and that is precisely the tab most
    // in need of learning that a new build exists.
    const timer = window.setInterval(check, UPDATE_INTERVAL);

    // Cleared when the page goes away. It costs almost nothing to leave running — the
    // callback returns immediately unless the document is visible — but a timer that
    // outlives its page is the kind of thing that quietly becomes a leak later.
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });

    // Coming back to a backgrounded tab is the moment a stale build is most likely and
    // least disruptive to replace.
    document.addEventListener('visibilitychange', () => {
        if (Date.now() - lastCheck > UPDATE_INTERVAL) {
            check();
        }
    });
}

/**
 * Tell the application when the connection comes and goes.
 *
 * An offline-capable app that gives no sign it is offline is indistinguishable from a
 * broken one, and `navigator.onLine` is the only signal the platform offers.
 *
 * Called from `boot()`, not from `registerServiceWorker()`. It used to hang off a
 * successful registration, which put the signal behind the one thing whose absence makes
 * it most needed: `service_worker.enabled` is off by default, so the majority of Pwax
 * applications never received `pwax:online` or `pwax:offline` at all — and neither did one
 * served over plain HTTP in development, where registration cannot succeed. These are
 * `window` events about the network; the worker has nothing to do with them.
 *
 * Idempotent, because `boot()` runs again on `window.pwax.start()` and a second pair of
 * listeners would announce every change twice.
 */
export function watchConnectivity() {
    if (connectivityWatched) {
        return;
    }

    connectivityWatched = true;

    const fire = (name) => () => document.dispatchEvent(new CustomEvent(name));

    window.addEventListener('online', fire('pwax:online'));
    window.addEventListener('offline', fire('pwax:offline'));
}

/**
 * The registration, or null where service workers are unavailable.
 *
 * A free function rather than a method, so nothing here depends on `this`. These are
 * published on `window.pwax.sw`, and `const { applyUpdate } = window.pwax.sw` is a
 * perfectly ordinary thing to write — it must not be the difference between working and
 * throwing.
 */
function currentRegistration() {
    return navigator.serviceWorker
        ? navigator.serviceWorker.getRegistration().then((r) => r ?? null)
        : Promise.resolve(null);
}

/**
 * The programmatic side of the worker, published as `window.pwax.sw`.
 */
export function createServiceWorkerApi() {
    return {
        /**
         * Take a waiting update now, rather than when the last tab closes.
         *
         * The page reloads once the new worker takes control, because half the
         * application would otherwise be running against the other half's caches.
         *
         * @returns {Promise<boolean>} false when there was nothing waiting.
         */
        async applyUpdate() {
            // `pendingUpdate` first, because it carries the flag that permits the reload.
            if (pendingUpdate) {
                pendingUpdate.activate();

                return true;
            }

            // Nothing was announced to this page — the worker was already waiting when it
            // loaded, or another tab took the announcement. The message still gets through;
            // the reload is then the browser's, on `controllerchange`.
            const waiting = (await currentRegistration())?.waiting;

            if (!waiting) {
                return false;
            }

            waiting.postMessage({ type: 'PWAX_SKIP_WAITING' });

            return true;
        },

        /** Ask the browser to check for a new worker now. */
        async update() {
            if (!navigator.serviceWorker) {
                return null;
            }

            const registration = await navigator.serviceWorker.getRegistration();
            await registration?.update();

            return registration ?? null;
        },

        /**
         * Delete every Pwax cache, including the precache.
         *
         * Reach for this when the device itself should be left with nothing — a shared
         * terminal at the end of a shift, a kiosk being handed on. For routine sign-out
         * there is no need to clear anything: caches are shared across visitors, and the
         * next person to use the device gets the same offline app the last one had.
         */
        clearCaches() {
            const controller = navigator.serviceWorker?.controller;

            if (!controller) {
                return Promise.resolve(false);
            }

            return new Promise((resolve) => {
                const channel = new MessageChannel();
                const timer = window.setTimeout(() => resolve(false), 5000);

                channel.port1.onmessage = () => {
                    window.clearTimeout(timer);
                    resolve(true);
                };

                controller.postMessage({ type: 'PWAX_CLEAR_CACHES' }, [channel.port2]);
            });
        },

        /**
         * Unregister the worker entirely. */
        async unregister() {
            const registration = await navigator.serviceWorker?.getRegistration();

            return registration ? registration.unregister() : false;
        },

        /**
         * The worker currently controlling this page, or null.
         *
         * Named `controller`, not `registration`, because that is what it is: a
         * `ServiceWorker`, not a `ServiceWorkerRegistration`. Call it the latter and
         * `.waiting`, `.scope`, `.update()` and `.unregister()` are all `undefined` on
         * the object the name promised would have them — a failure that reads as the
         * worker not being ready rather than the property being wrong.
         */
        get controller() {
            return navigator.serviceWorker?.controller ?? null;
        },

        /**
         * The registration, for real. Asynchronous, because the platform's is.
         */
        registration() {
            return currentRegistration();
        },
    };
}
