/**
 * The navigation progress bar.
 *
 * There are two waits in a Pwax application and they are not the same wait.
 *
 * The **first load** is a document arriving: the browser has its own indicator for that,
 * and the shell covers the gap between HTML and mount with a centred spinner. Nothing
 * here is involved.
 *
 * A **navigation** is different. The address bar does not move, the page does not blank,
 * and without something to say so a slow route looks like a dead link. This is that
 * something — and deliberately the only thing that moves, because the page underneath
 * stays exactly where it was until its replacement is ready.
 *
 * Three details are the difference between feedback and jitter:
 *
 *   - **It waits before appearing.** Most navigations finish in a couple of hundred
 *     milliseconds, and a bar that flashes on and off for each of them is noise. Under
 *     `delay` it costs one timer and touches no DOM at all.
 *   - **It never arrives on its own.** Real progress cannot be known — a payload has no
 *     length the client can see before it lands — so it eases towards a ceiling it never
 *     reaches. Claiming to be finished and then waiting is the one thing that makes a
 *     progress bar read as a lie.
 *   - **It finishes by completing.** The stroke to full, then the fade. Vanishing from
 *     halfway says the navigation was abandoned.
 */

/** Where the bar starts. Visible immediately, so appearing does not look like a stutter. */
const MINIMUM = 0.08;

/** Where the trickle stops. Any closer to the end and the last stretch reads as stuck. */
const CEILING = 0.94;

/** How often the trickle advances. Slow enough to be smooth, cheap enough to ignore. */
const TICK = 220;

/** How long the completing stroke and its fade need before the bar can be reset. */
const FADE = 320;

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
    let at = 0;
    let running = false;

    /**
     * The bar element, created on the first navigation slow enough to need one.
     *
     * Not rendered by the shell. An application whose every navigation is fast never puts
     * this in the document at all.
     */
    function element() {
        if (bar && bar.isConnected) {
            return bar;
        }

        bar = doc.getElementById('pwax-progress');

        if (!bar) {
            bar = doc.createElement('div');
            bar.id = 'pwax-progress';
            // Decorative. The navigation is announced to assistive technology by the live
            // region in the shell, which says what the new page is — far more use than a
            // bar reading out percentages it is inventing.
            bar.setAttribute('aria-hidden', 'true');
            doc.body.appendChild(bar);
        }

        return bar;
    }

    /** Move the bar to wherever `at` now is. */
    function paint() {
        element().style.transform = `scaleX(${at})`;
    }

    function stopTimers() {
        clearTimeout(timer);
        clearInterval(ticker);
        timer = null;
        ticker = null;
    }

    function show() {
        const node = element();

        node.classList.add('pwax-progress-visible');
        node.classList.remove('pwax-progress-done');

        at = MINIMUM;
        paint();

        if (trickle) {
            // Decelerating, not linear. A linear trickle looks like a countdown, and a
            // countdown that never lands is exactly the impression to avoid.
            ticker = setInterval(() => {
                at += (CEILING - at) * 0.12;
                paint();
            }, TICK);
        }
    }

    /** Put the bar back to nothing, ready for the next navigation. */
    function reset(node) {
        node.classList.remove('pwax-progress-visible', 'pwax-progress-done');
        node.style.transform = 'scaleX(0)';
    }

    return {
        /**
         * A navigation has started.
         *
         * Idempotent: a visitor who clicks three links in a row gets one bar that keeps
         * going, not three that restart each other back at zero.
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
         * If the bar never appeared it never will — this returns without touching the DOM,
         * which is the common case and the whole reason `delay` exists.
         */
        done() {
            if (!running) {
                return;
            }

            running = false;
            stopTimers();

            if (!bar || !bar.classList.contains('pwax-progress-visible')) {
                return;
            }

            at = 1;
            paint();

            // Completing and then fading is what reads as "done". The class carries the
            // fade; its end is not waited on, because a browser that has throttled this
            // tab may never report one.
            bar.classList.add('pwax-progress-done');

            const node = bar;
            setTimeout(() => reset(node), FADE);
        },

        /** Whether a navigation is currently being tracked. */
        get active() {
            return running;
        },
    };
}
