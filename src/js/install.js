/**
 * Installing the app, and knowing whether it is installed.
 *
 * A browser will not show its own install button on most platforms any more — Chromium
 * hides it behind a menu, and iOS has never had one. The affordance an application needs
 * is its own, shown at a moment it chooses, and that requires catching
 * `beforeinstallprompt` before the browser gives up on it and keeping the event to call
 * later. Nothing here happens automatically: the package captures the event and tells the
 * application; the application decides whether and when to ask.
 *
 * Alongside it, two small platform APIs that belong to the same question — is this thing
 * installed, and how do I behave now that it is: the badge on the app icon, and whether
 * storage is safe from eviction.
 */

/** The captured `beforeinstallprompt`, or null once used or never offered. */
let deferred = null;

let installed = false;

/**
 * Is the app running as an installed app rather than in a browser tab?
 *
 * `display-mode` covers everything installed from a manifest. `navigator.standalone` is
 * iOS's own answer and predates the media query; Safari still reports it and only it for
 * an app added to the home screen.
 */
export function standalone() {
    const modes = ['standalone', 'fullscreen', 'minimal-ui', 'window-controls-overlay'];

    if (typeof window.matchMedia === 'function') {
        for (const mode of modes) {
            if (window.matchMedia(`(display-mode: ${mode})`).matches) {
                return true;
            }
        }
    }

    return window.navigator.standalone === true;
}

/**
 * Watch for the browser offering installation, and for it happening.
 *
 * Registered as early as the runtime boots. `beforeinstallprompt` fires once, early, and
 * is not replayed — a listener added after it has fired never sees it, and the chance to
 * offer installation is gone for that page load.
 */
export function watchInstall() {
    installed = standalone();

    window.addEventListener('beforeinstallprompt', (event) => {
        // Without this the browser shows its own prompt and the application never gets a
        // say in when it appears.
        event.preventDefault();
        deferred = event;

        document.dispatchEvent(new CustomEvent('pwax:installable'));
    });

    window.addEventListener('appinstalled', () => {
        deferred = null;
        installed = true;

        document.dispatchEvent(new CustomEvent('pwax:installed'));
    });
}

/**
 * The install API on `window.pwax.install`.
 */
export function createInstallApi() {
    return {
        /** Can `prompt()` do anything right now? */
        get available() {
            return deferred !== null;
        },

        /** Has the app been installed — in this session, or before it? */
        get installed() {
            return installed || standalone();
        },

        /** Is this window an installed app rather than a browser tab? */
        get standalone() {
            return standalone();
        },

        /**
         * Show the browser's install prompt.
         *
         * Resolves to `'accepted'`, `'dismissed'`, or `'unavailable'` when there was no
         * captured event to show — already installed, not eligible, or iOS, which has no
         * programmatic install at all and needs a "share, then Add to Home Screen" hint of
         * your own.
         *
         * The captured event is single-use. A dismissal is not permanent, but the browser
         * decides when to offer another one, so do not count on being able to ask twice.
         */
        async prompt() {
            if (!deferred) {
                return 'unavailable';
            }

            const event = deferred;
            deferred = null;

            try {
                await event.prompt();

                const { outcome } = await event.userChoice;

                return outcome === 'accepted' ? 'accepted' : 'dismissed';
            } catch {
                return 'unavailable';
            }
        },
    };
}

/**
 * The badge on the installed app's icon.
 *
 * Chromium-only and quietly absent everywhere else, so both calls are no-ops rather than
 * errors — an application should not have to feature-detect to show an unread count.
 */
export function createBadgeApi() {
    return {
        get supported() {
            return typeof navigator.setAppBadge === 'function';
        },

        async set(count) {
            if (typeof navigator.setAppBadge !== 'function') {
                return false;
            }

            try {
                await navigator.setAppBadge(count);

                return true;
            } catch {
                return false;
            }
        },

        async clear() {
            if (typeof navigator.clearAppBadge !== 'function') {
                return false;
            }

            try {
                await navigator.clearAppBadge();

                return true;
            } catch {
                return false;
            }
        },
    };
}

/**
 * Storage: how much is used, and whether it can be evicted.
 *
 * This matters more here than in most applications. Pwax precaches the entire app, and
 * under storage pressure a browser evicts whole origins — so the failure mode is not a
 * slow page, it is "it stopped working offline", with nothing in the app to explain it.
 * Asking for persistence is the one thing that prevents it, and nobody asks by default
 * because the prompt is a real prompt on some platforms.
 */
export function createStorageApi() {
    return {
        /**
         * Bytes used and available, or null where the browser will not say.
         */
        async estimate() {
            if (!navigator.storage || typeof navigator.storage.estimate !== 'function') {
                return null;
            }

            try {
                const { usage = 0, quota = 0 } = await navigator.storage.estimate();

                return { usage, quota, ratio: quota > 0 ? usage / quota : 0 };
            } catch {
                return null;
            }
        },

        /** Is this origin's storage already exempt from eviction? */
        async persisted() {
            if (!navigator.storage || typeof navigator.storage.persisted !== 'function') {
                return false;
            }

            try {
                return await navigator.storage.persisted();
            } catch {
                return false;
            }
        },

        /**
         * Ask for it.
         *
         * Deliberately never called for you. On some platforms this is granted silently
         * on engagement signals, and on others it shows a permission prompt — which is
         * the application's decision to spend, not the package's.
         */
        async persist() {
            if (!navigator.storage || typeof navigator.storage.persist !== 'function') {
                return false;
            }

            try {
                return await navigator.storage.persist();
            } catch {
                return false;
            }
        },
    };
}
