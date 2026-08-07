/**
 * Service worker registration and update handling.
 *
 * Registering is the easy half. The half that is usually missed is telling the user when
 * a new version is waiting: without it, a returning visitor keeps running the previous
 * build until they happen to close every tab. `pwax:update-available` fires with an
 * `activate()` callback so the application can offer a "reload to update" prompt.
 */

export function registerServiceWorker(path, { scope = '/' } = {}) {
    if (!path || !('serviceWorker' in navigator)) {
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

    let reloading = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (reloading) {
            return;
        }

        reloading = true;
        window.location.reload();
    });
}

function announce(worker) {
    document.dispatchEvent(
        new CustomEvent('pwax:update-available', {
            detail: {
                activate: () => worker.postMessage({ type: 'PWAX_SKIP_WAITING' }),
            },
        })
    );
}
