/**
 * The navigation progress bar.
 *
 * A client-side navigation has no address-bar spinner, so without something like this the
 * only feedback a slow page gives is that nothing has happened yet. Replacing the page
 * with the word "Loading" is worse than no feedback at all: it throws away what the
 * visitor was reading, collapses the layout, and then throws it away again on the way
 * back.
 *
 * So this is deliberately the *only* thing that moves during a navigation. The page under
 * it stays exactly where it was until the next one is ready to take its place.
 *
 * Three details make it feel right rather than merely present:
 *
 *   - **It waits before appearing.** Most navigations in an application like this finish
 *     in under a couple of hundred milliseconds, and a bar that flashes on and off for
 *     every one of them reads as jitter. Nothing is shown until `delay` has passed.
 *   - **It never reaches the end on its own.** Progress cannot be known — the payload has
 *     no length the client can see before it arrives — so it eases towards a ceiling it
 *     never touches. Claiming 100% and then waiting is the one thing that makes a
 *     progress bar feel like a lie.
 *   - **It finishes by completing, not by vanishing.** The jump to full and the fade out
 *     are what read as "done".
 */

/** Where the trickle stops. Anything closer to 1 and the last stretch reads as stuck. */
const CEILING = 0.94;

/** How often the trickle advances. Slow enough to be smooth, cheap enough to ignore. */
const TICK = 220;

/**
 * @param {object} [options]
 * @param {number} [options.delay]    ms of silence before the bar appears at all
 * @param {boolean} [options.trickle] advance on a timer, not only on real events
 * @param {Document} [options.document]
 */
export function createProgress({ delay = 250, trickle = true, document: doc = document } = {}) {
    /** @type {HTMLElement|null} */
    let bar = null;
    let timer = null;
    let ticker = null;
    let progress = 0;
    let running = false;

    function element() {
        if (bar && bar.isConnected) {
            return bar;
        }

        bar = doc.getElementById('pwax-progress');

        if (!bar) {
            bar = doc.createElement('div');
            bar.id = 'pwax-progress';
            // Decorative. The navigation is announced to assistive technology by the
            // live region in the shell, which says what the new page is — far more use
            // than a bar reading out percentages it is making up.
            bar.setAttribute('aria-hidden', 'true');
            doc.body.appendChild(bar);
        }

        return bar;
    }

    /**
     * Take over a bar the server rendered already running.
     *
     * The first load is the one this cannot start for itself: the bar has to be moving
     * while the document is still being parsed, which is before any of this exists. So the
     * shell renders it already visible, advancing under a CSS animation that needs no
     * JavaScript at all, and the runtime adopts it here rather than starting a second one.
     *
     * Without this the first load had a different indicator from every navigation after
     * it — the one moment a visitor is most likely to be waiting, and the one that looked
     * least like the rest of the application.
     */
    function adopt() {
        const existing = doc.getElementById('pwax-progress');

        if (!existing || !existing.classList.contains('pwax-progress-visible')) {
            return;
        }

        bar = existing;
        running = true;

        // Whatever the animation had reached is where this picks up. Reading it back is
        // not worth the layout it would cost: the next thing to happen is `done()`, which
        // goes to full from wherever it is.
        progress = CEILING;
    }

    function paint() {
        const node = element();

        node.style.transform = `scaleX(${progress})`;
    }

    function advance() {
        // Decelerating: fast while there is a lot of bar left, slower as it runs out.
        // A linear trickle looks like a countdown, and a countdown that never lands is
        // exactly the thing to avoid.
        progress += (CEILING - progress) * 0.12;
        paint();
    }

    function show() {
        const node = element();

        node.classList.add('pwax-progress-visible');
        node.classList.remove('pwax-progress-done');

        progress = 0.08;
        paint();

        if (trickle) {
            ticker = setInterval(advance, TICK);
        }
    }

    return {
        /**
         * A navigation has started.
         *
         * Idempotent: a visitor who clicks three links in a row gets one bar that keeps
         * going, not three that restart each other.
         */
        start() {
            if (running) {
                return;
            }

            running = true;
            timer = setTimeout(show, delay);
        },

        /**
         * A navigation has finished, one way or the other.
         *
         * If the bar never appeared it never will — this returns without touching the
         * DOM, which is the common case and the reason `delay` exists.
         */
        done() {
            if (!running) {
                return;
            }

            running = false;
            clearTimeout(timer);
            clearInterval(ticker);
            timer = null;
            ticker = null;

            if (!bar || !bar.classList.contains('pwax-progress-visible')) {
                return;
            }

            // A CSS animation beats an inline transform, so the boot animation has to be
            // taken off before the completing stroke can be painted. Removing it is also
            // what stops a slow first load from creeping on after the app has mounted.
            bar.classList.remove('pwax-progress-boot');

            progress = 1;
            paint();

            // Completing and then fading is what reads as "done". The class carries the
            // fade; the transition end is not waited on, because a browser that has
            // throttled this tab may never report one.
            bar.classList.add('pwax-progress-done');

            const node = bar;
            setTimeout(() => {
                node.classList.remove('pwax-progress-visible', 'pwax-progress-done');
                node.style.transform = 'scaleX(0)';
            }, 320);
        },

        /** Take over a bar the shell rendered already running. */
        adopt,

        /** Whether a navigation is currently being tracked. */
        get active() {
            return running;
        },
    };
}

/**
 * The progress API, having first taken over anything the shell left running.
 *
 * Separate from `createProgress` so the constructor stays testable without a document
 * that has already been rendered into.
 */
export function bootProgress(options = {}) {
    const progress = createProgress(options);

    progress.adopt();

    return progress;
}
