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
 * Paths Pwax has served a worker from before now.
 *
 * A registration outlives the page that made it, so when the script moves the old one
 * keeps running — answering from caches built by a version of the application that is no
 * longer deployed. Nothing about loading the new page retires it on its own.
 */
const LEGACY_PATHS = ['/service-worker.js', '/serviceworker.js', '/sw.js'];

export function registerServiceWorker(path, { scope = '/', legacyPaths = [] } = {}) {
    // Truthiness, not `in`. Service workers require a secure context, and on a page
    // served over plain HTTP the property is present on the prototype but the value is
    // `undefined` — so an `in` check passes and the next line throws.
    if (!path || !navigator.serviceWorker) {
        return Promise.resolve(null);
    }

    return navigator.serviceWorker
        .register(path, { scope })
        .then(async (registration) => {
            watchForUpdates(registration);
            watchConnectivity();

            // Only after the replacement is registered. Retiring the old worker first
            // would leave a device that lost its connection in between with no worker at
            // all — offline, and now without the offline capability it installed this for.
            await retireLegacyWorkers(path, legacyPaths);

            return registration;
        })
        .catch((error) => {
            console.warn('pwax: service worker registration failed', error);

            return null;
        });
}

/**
 * Unregister any worker of ours left at a path we no longer serve from.
 *
 * Scoped to a known list rather than "everything that is not the current script": another
 * library may legitimately own a worker on this origin, and unregistering someone else's
 * is an outage with no obvious cause.
 */
async function retireLegacyWorkers(current, extra) {
    if (!navigator.serviceWorker.getRegistrations) {
        return;
    }

    const stale = new Set([...LEGACY_PATHS, ...extra]);

    stale.delete(new URL(current, location.origin).pathname);

    try {
        for (const registration of await navigator.serviceWorker.getRegistrations()) {
            const worker = registration.active || registration.waiting || registration.installing;

            if (!worker) {
                continue;
            }

            const url = new URL(worker.scriptURL);

            if (url.origin === location.origin && stale.has(url.pathname)) {
                await registration.unregister();
            }
        }
    } catch (error) {
        // A browser that refuses to enumerate registrations is not a reason to fail the
        // page. The worst case is one stale worker that the browser retires on its own.
        console.warn('pwax: could not retire an old service worker', error);
    }
}

function watchForUpdates(registration) {
    // True once *this page* has asked a waiting worker to take over. Scoped to the
    // registration rather than the module so that one page's decision cannot be read as
    // another's — it is the difference between an expected reload and a lost form.
    let activating = false;
    let reloading = false;
    let lastCheck = Date.now();

    const announce = (worker) => {
        document.dispatchEvent(
            new CustomEvent('pwax:update-available', {
                detail: {
                    activate: () => {
                        activating = true;
                        worker.postMessage({ type: 'PWAX_SKIP_WAITING' });
                    },
                },
            })
        );
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
         * Delete every Pwax cache.
         *
         * Worth calling on sign-out. The worker refuses to store anything the server
         * marked `no-store`, so a signed-in user's pages never reach disk in the first
         * place — but a component that renders differently for an administrator does,
         * and on a shared device that is worth clearing.
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
         * @param {string} identity  `window.pwax.config.identity` for the user signing out.
         */
        forgetIdentity(identity) {
            const controller = navigator.serviceWorker?.controller;

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

        get registration() {
            return navigator.serviceWorker?.controller ?? null;
        },
    };
}
