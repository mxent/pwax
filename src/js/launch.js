/**
 * Being launched by the operating system rather than by a click.
 *
 * An installed app can be opened three ways the manifest declares and the browser then
 * delivers through one API: `file_handlers` (the user opened a .csv the app claims),
 * `protocol_handlers` (something followed a `web+thing:` link), and a GET `share_target`
 * (the user shared a page to the app). All three arrive as a launch, and a launch that
 * nothing consumes is silently discarded — the app opens on whatever page it opened on, the
 * file the user double-clicked is gone, and there is no error anywhere to explain it.
 *
 * The queue exists precisely because the launch usually happens before the page has
 * finished loading, so the consumer has to be set as early as the runtime boots and the
 * result buffered for whoever asks later.
 *
 * Nothing here reads or uploads a file. `launchParams.files` are `FileSystemFileHandle`s,
 * which is a capability the user granted to the application, and handing them somewhere
 * else is the application's decision to make.
 */

/** Launches delivered before anyone asked for them. */
let queued = [];

/** The registered consumer, if any. */
let consumer = null;

/**
 * How many unclaimed launches to hold.
 *
 * One is the realistic number — a launch opens or focuses a window, and the app boots
 * immediately after. The bound exists because this is a global that nothing is obliged to
 * drain, and an app that never calls `consume()` should not accumulate file handles for the
 * lifetime of the window.
 */
const MAX_QUEUED = 8;

/**
 * Start consuming launches. Called before anything is awaited, for the reason above.
 */
export function watchLaunch() {
    queued = [];

    if (!('launchQueue' in window) || !window.launchQueue) {
        return;
    }

    try {
        window.launchQueue.setConsumer((params) => deliver(params));
    } catch {
        // Present but unusable — a browser mid-implementation, or a page that is not a
        // launch target. Nothing to do and nothing worth saying.
    }
}

/**
 * Hand a launch to whoever wants it, and route if nobody objects.
 *
 * @param {{files?: ArrayLike<any>, targetURL?: string}} params
 */
function deliver(params) {
    const launch = {
        files: params && params.files ? Array.from(params.files) : [],
        targetURL: (params && params.targetURL) || null,
        // Whether the runtime has already routed to `targetURL`. Read it in a consumer that
        // may be handed a buffered launch, so it can tell "this just happened" from "this
        // happened before you were listening and has already been acted on".
        navigated: false,
    };

    // Every ordinary open of an installed app produces a launch with neither, and treating
    // that as an event to act on would fire on every cold start.
    if (launch.files.length === 0 && launch.targetURL === null) {
        return;
    }

    const allowed = document.dispatchEvent(
        new CustomEvent('pwax:launch', { detail: launch, cancelable: true })
    );

    if (!consumer) {
        queue(launch);
    }

    settle(launch, allowed && (!consumer || consumer(launch) !== false));
}

/**
 * Route to a launch's target, once and only once.
 *
 * A launch carrying a URL and no files is a protocol handler or a shared link, and under
 * `launch_handler: focus-existing` the browser only brings the window forward — it does not
 * navigate. Doing nothing there means following a `web+thing:` link opens the app on
 * yesterday's page, which is wrong every single time. With files it is the opposite: the
 * target URL is the handler page, the app is already on it, and the files are the payload.
 *
 * `navigated` is what keeps a launch that was routed on arrival — before any consumer
 * existed — from being routed a second time when one registers and drains the backlog.
 *
 * @param {boolean} allowed  Neither the event nor the consumer objected.
 */
function settle(launch, allowed) {
    if (!allowed || launch.navigated || launch.files.length > 0 || !launch.targetURL) {
        return;
    }

    launch.navigated = true;

    navigate(launch.targetURL);
}

function queue(launch) {
    if (queued.length >= MAX_QUEUED) {
        queued.shift();
    }

    queued.push(launch);
}

/**
 * Go to a launch's target URL.
 *
 * Through the router when there is one, so the launch costs a payload rather than a full
 * reload. `window.pwax.router` is read at call time rather than injected, because a launch
 * can be delivered before `boot()` has built one.
 */
function navigate(url) {
    let target;

    try {
        target = new URL(url, window.location.href);
    } catch {
        return;
    }

    // Same origin only. `targetURL` comes from the browser and should always be in scope,
    // but this is the one value here that decides where the app goes.
    if (target.origin !== window.location.origin) {
        return;
    }

    const path = target.pathname + target.search + target.hash;

    if (path === window.location.pathname + window.location.search + window.location.hash) {
        return;
    }

    const router = window.pwax && window.pwax.router;

    if (router) {
        router.push(path);

        return;
    }

    window.location.assign(path);
}

/**
 * The launch API on `window.pwax.launch`.
 */
export function createLaunchApi() {
    return {
        /** Does this browser deliver launches at all? Chromium only, at the time of writing. */
        get supported() {
            return 'launchQueue' in window;
        },

        /** Launches that arrived before a consumer was registered. */
        get pending() {
            return queued.slice();
        },

        /**
         * Receive launches, including any that arrived before this was called.
         *
         * Return `false` to keep the runtime from navigating to the launch's target URL.
         *
         *     window.pwax.launch.consume(({ files, targetURL }) => {
         *         for (const handle of files) open(handle);
         *     });
         *
         * Returns a function that unregisters it.
         */
        consume(fn) {
            if (typeof fn !== 'function') {
                return () => {};
            }

            consumer = fn;

            const backlog = queued;
            queued = [];

            for (const launch of backlog) {
                settle(launch, fn(launch) !== false);
            }

            return () => {
                if (consumer === fn) {
                    consumer = null;
                }
            };
        },
    };
}

/**
 * Share something from the app.
 *
 * The other direction from `share_target`, and the reason it is here: an application that
 * declares itself a share target almost always wants to share too, and `navigator.share`
 * throws rather than resolving on every platform that does not have it.
 */
export function createShareApi() {
    return {
        get supported() {
            return typeof navigator.share === 'function';
        },

        /**
         * Open the platform share sheet.
         *
         * Resolves `'shared'`, `'dismissed'` when the user cancelled, or `'unavailable'`
         * where there is no share sheet — so a caller can fall back to copying a link
         * without having to feature-detect first.
         *
         * Must be called from a user gesture; browsers reject it otherwise, which this
         * reports as `'dismissed'`.
         */
        async share(data) {
            if (typeof navigator.share !== 'function') {
                return 'unavailable';
            }

            if (
                data &&
                data.files &&
                typeof navigator.canShare === 'function' &&
                !navigator.canShare(data)
            ) {
                return 'unavailable';
            }

            try {
                await navigator.share(data || {});

                return 'shared';
            } catch (error) {
                return error && error.name === 'AbortError' ? 'dismissed' : 'unavailable';
            }
        },
    };
}

/** Test seam: forget the consumer and anything buffered. */
export function resetLaunches() {
    queued = [];
    consumer = null;
}
