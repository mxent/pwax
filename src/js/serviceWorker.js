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
            watchConnectivity();

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

    window.setInterval(check, UPDATE_INTERVAL);

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
 */
function watchConnectivity() {
    if (connectivityWatched) {
        return;
    }

    connectivityWatched = true;

    const fire = (name) => () => document.dispatchEvent(new CustomEvent(name));

    window.addEventListener('online', fire('pwax:online'));
    window.addEventListener('offline', fire('pwax:offline'));
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
            const waiting = pendingUpdate || (await this.registration())?.waiting;

            if (!waiting) {
                return false;
            }

            // `pendingUpdate` carries the flag that permits the reload; a registration
            // found cold does not, so it gets the message and the browser's own reload.
            if (pendingUpdate) {
                pendingUpdate.activate();
            } else {
                waiting.postMessage({ type: 'PWAX_SKIP_WAITING' });
            }

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
         * The blunt instrument. `forgetIdentity()` is almost always the one you want on
         * sign-out: it drops what belonged to that person and leaves the framework, the
         * components and the shell in place, so the next visitor gets an application that
         * still works offline instead of one that downloads itself again.
         *
         * Reach for this when the device itself should be left with nothing — a shared
         * terminal at the end of a shift, a kiosk being handed on.
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
         * Drop everything cached for one signed-in identity.
         *
         * What to call on sign-out. Unlike `clearCaches()` it leaves the precache — the
         * framework, the components, the shell — in place, so the next person to use the
         * device gets an application that still works offline rather than one that has to
         * download itself again.
         *
         * Called with no argument it uses the identity the runtime currently holds, which
         * is the one you want and the one that is hard to get right by hand: reading
         * `window.pwax.config.identity` into a variable early and passing it back later
         * hands over whoever was signed in *then*. The runtime keeps its own copy in step
         * with the server on every page load.
         *
         * @param {string} [identity]  Defaults to the current `window.pwax.config.identity`.
         */
        forgetIdentity(identity) {
            const controller = navigator.serviceWorker?.controller;
            identity = identity || window.pwax?.config?.identity;

            if (!controller || !identity) {
                return Promise.resolve(false);
            }

            return new Promise((resolve) => {
                const channel = new MessageChannel();
                const timer = window.setTimeout(() => resolve(false), 5000);

                channel.port1.onmessage = () => {
                    window.clearTimeout(timer);
                    resolve(true);
                };

                controller.postMessage({ type: 'PWAX_FORGET_IDENTITY', identity }, [channel.port2]);
            });
        },

        /** Unregister the worker entirely. */
        async unregister() {
            const registration = await navigator.serviceWorker?.getRegistration();

            return registration ? registration.unregister() : false;
        },

        /**
         * The worker currently controlling this page, or null.
         *
         * This used to be called `registration`, and returned exactly this — a
         * `ServiceWorker`, not a `ServiceWorkerRegistration`. So `.waiting`, `.scope`,
         * `.update()` and `.unregister()` were all `undefined` on the object the name
         * promised would have them, and the failure looked like the worker not being
         * ready rather than the property being wrong.
         */
        get controller() {
            return navigator.serviceWorker?.controller ?? null;
        },

        /**
         * The registration, for real. Asynchronous, because the platform's is.
         */
        registration() {
            return navigator.serviceWorker
                ? navigator.serviceWorker.getRegistration().then((r) => r ?? null)
                : Promise.resolve(null);
        },
    };
}
