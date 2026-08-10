/**
 * What is on screen during a navigation.
 *
 * The page component used to render the loader *instead of* the current component the
 * moment a navigation began. Every click therefore threw away what the visitor was
 * reading, collapsed the layout to the height of one line, and expanded it again when the
 * next page arrived — a flash of nothing between every two pages, worst on exactly the
 * connections where it matters most.
 *
 * These assert the replacement rule: `component` changes only when a replacement is ready,
 * and the key the transition runs on moves with the rendered page rather than with the
 * navigation.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPageComponent } from '../../src/js/page.js';

/** A payload the runtime can mount with no network and no module fetch. */
const payloadFor = (name) => ({ template: `<p>${name}</p>` });

const noStyles = {
    link: async () => {},
    script: async () => {},
    acquire: () => {},
    release: () => {},
};

/**
 * `createPageComponent` returns plain options, so its methods can be called against a
 * hand-made state object — no mounting, no router, no layout.
 */
function bind(page) {
    const state = {
        ...page.data(),
        $route: { fullPath: '/one' },
        $router: { replace: vi.fn(), push: vi.fn() },
        $nextTick: (fn) => fn(),
    };

    for (const [name, fn] of Object.entries(page.methods)) {
        state[name] = fn.bind(state);
    }

    return state;
}

/** A server that hands back control over when each request resolves. */
function deferredServer() {
    const pending = [];

    return {
        json: (path) => new Promise((resolve) => pending.push(() => resolve(payloadFor(path)))),
        settle: () => pending.shift()(),
    };
}

describe('what stays on screen during a navigation', () => {
    beforeEach(() => {
        // `mount()` reaches for the global Vue. `markRaw` can be the identity — the test
        // is about *when* `component` changes, not what Vue then does with it — but
        // `compile` has to be present, because that is how the runtime recognises the
        // full Vue build and these payloads carry templates rather than render functions.
        globalThis.Vue = { markRaw: (value) => value, compile: () => () => null };
    });

    afterEach(() => {
        delete globalThis.Vue;
    });

    it('keeps the current page mounted while the next one is being fetched', async () => {
        const http = deferredServer();
        const state = bind(
            createPageComponent({
                http,
                styles: noStyles,
                config: {},
                initial: null,
                progress: { start: vi.fn(), done: vi.fn() },
            })
        );

        const first = state.visit('/one');
        http.settle();
        await first;

        const rendered = state.component;
        expect(rendered).toBeTruthy();
        expect(state.renderedPath).toBe('/one');

        // A second navigation, deliberately left in flight.
        const second = state.visit('/two');

        expect(state.loading).toBe(true);
        // The whole fix, in one assertion: the page being rendered is still the page the
        // visitor was reading.
        expect(state.component).toBe(rendered);
        expect(state.renderedPath).toBe('/one');

        http.settle();
        await second;

        expect(state.component).not.toBe(rendered);
        expect(state.renderedPath).toBe('/two');
    });

    it('mounts the resolved options directly, with no async wrapper', async () => {
        const http = deferredServer();
        const state = bind(
            createPageComponent({ http, styles: noStyles, config: {}, initial: null })
        );

        const visit = state.visit('/one');
        http.settle();
        await visit;

        // `toOptions()` has already resolved this. Wrapping it in `defineAsyncComponent`
        // asked Vue to await a promise that was never pending, and — because every call
        // produces a distinct component type — made Vue rebuild the page from scratch on
        // every navigation, including a return to a path it had already rendered.
        expect(state.component).toEqual(expect.objectContaining({ template: '<p>/one</p>' }));
        expect(state.component.__async).toBeUndefined();
    });

    it('reuses the component type when the same path is rendered again', async () => {
        const http = deferredServer();
        const state = bind(
            createPageComponent({ http, styles: noStyles, config: {}, initial: null })
        );

        const first = state.visit('/one');
        http.settle();
        await first;

        const type = state.component;

        const again = state.visit('/one');
        http.settle();
        await again;

        // Different object, same shape — what matters is that the *type* Vue sees is a
        // plain options object it can compare, not a fresh async wrapper every time.
        expect(state.component).toEqual(type);
        expect(state.renderedPath).toBe('/one');
    });

    it('leaves the rendered page in place when a navigation fails', async () => {
        let fail = false;
        const state = bind(
            createPageComponent({
                http: {
                    json: async (path) => {
                        if (fail) {
                            throw new TypeError('Failed to fetch');
                        }

                        return payloadFor(path);
                    },
                },
                styles: noStyles,
                config: {},
                initial: null,
                progress: { start: vi.fn(), done: vi.fn() },
            })
        );

        await state.visit('/one');

        const rendered = state.component;
        expect(rendered).toBeTruthy();

        fail = true;
        await state.visit('/missing');

        // The error template replaces the page — that part is deliberate, a failed
        // navigation needs somewhere to say so. What must not move is `renderedPath`:
        // it keys the transition, and advancing it on a failure would animate a page out
        // with nothing behind it, so going back would have nothing to return to.
        expect(state.error).toBeTruthy();
        expect(state.component).toBe(rendered);
        expect(state.renderedPath).toBe('/one');
    });

    it('runs the bar for a fetch and not for the inlined landing page', async () => {
        const start = vi.fn();

        const state = bind(
            createPageComponent({
                http: { json: async (path) => payloadFor(path) },
                styles: noStyles,
                config: {},
                initial: { url: '/one', component: payloadFor('/one') },
                progress: { start, done: vi.fn() },
            })
        );

        // The landing page is already in the document. There is no request to indicate,
        // and the shell's spinner has covered the load that delivered it.
        await state.visit('/one');
        expect(start).not.toHaveBeenCalled();

        await state.visit('/two');
        expect(start).toHaveBeenCalledTimes(1);
    });

    it('finishes the bar before swapping the page', async () => {
        const order = [];
        let component = null;

        const page = createPageComponent({
            http: { json: async (path) => payloadFor(path) },
            styles: noStyles,
            config: {},
            initial: null,
            progress: { start: () => order.push('start'), done: () => order.push('done') },
        });

        const state = bind(page);

        Object.defineProperty(state, 'component', {
            get: () => component,
            set: (value) => {
                order.push('swap');
                component = value;
            },
        });

        await state.visit('/one');

        // The bar completing says the waiting is over; the fade says the page changed.
        // In that order it reads as one sequence rather than two things at once.
        //
        // Relative order, not the exact list: `visit()` finishes the bar again in its
        // `finally` as a safety net for the paths that never reach the swap, and on the
        // real object that second call is a no-op.
        expect(order.indexOf('done')).toBeGreaterThan(order.indexOf('start'));
        expect(order.indexOf('swap')).toBeGreaterThan(order.indexOf('done'));
    });

    it('works without a progress bar at all', async () => {
        const state = bind(
            createPageComponent({
                http: { json: async (path) => payloadFor(path) },
                styles: noStyles,
                config: {},
                initial: null,
            })
        );

        // `progress: false` in config resolves to null, and every call site is optional.
        await expect(state.visit('/one')).resolves.toBeUndefined();
        expect(state.renderedPath).toBe('/one');
    });

    it('wraps the page swap in the browser View Transitions API', async () => {
        // The browser snapshots the outgoing page, the swap commits, and the browser
        // cross-fades between them in a single frame. The Vue `<transition>` wrapper
        // was removed because two-phase mount/unmount was the source of the empty
        // router-view flicker; the browser's snapshot mechanism does the same job
        // without interleaving the two phases.
        const start = vi.fn((update) => {
            update();
        });

        // jsdom provides `document` already. Adding `startViewTransition` to it
        // exercises the path: the runtime checks for the API and uses it; the rest of
        // the page (which still calls `document.dispatchEvent` to publish events) is
        // untouched.
        globalThis.document.startViewTransition = start;

        const http = deferredServer();
        const state = bind(
            createPageComponent({
                http,
                styles: noStyles,
                config: {},
                initial: null,
            })
        );

        const visit = state.visit('/one');
        http.settle();
        await visit;

        expect(start).toHaveBeenCalled();
        expect(state.component).toBeTruthy();

        delete globalThis.document.startViewTransition;
    });

    it('falls back to a synchronous swap when the View Transitions API is missing', async () => {
        // Browsers without the API (Safari < 18, older Chrome) do not throw; the swap
        // happens in the same call as the commit. The replacement is the previous
        // behaviour preserved, which is a flicker but not a regression.
        const original = globalThis.document?.startViewTransition;
        if (original) {
            delete globalThis.document.startViewTransition;
        }

        const http = deferredServer();
        const state = bind(
            createPageComponent({
                http,
                styles: noStyles,
                config: {},
                initial: null,
            })
        );

        const visit = state.visit('/one');
        http.settle();
        await visit;

        expect(state.component).toBeTruthy();

        if (original) {
            globalThis.document.startViewTransition = original;
        }
    });

    it('shows the loader only when there is nothing to keep', () => {
        const page = createPageComponent({
            http: { json: async () => ({}) },
            styles: noStyles,
            config: {},
            initial: null,
            templates: { loader: '<div id="waiting"></div>' },
        });

        // Guarded on the absence of a component, not on `loading` — that guard is what
        // used to replace the page you were reading.
        expect(page.template).toContain('v-if="!component"');
        expect(page.template).not.toContain('v-else-if="loading"');
    });
});
