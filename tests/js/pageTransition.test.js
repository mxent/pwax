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
        // `mount()` reaches for the global Vue. Only two functions of it are used here,
        // and both can be identity-ish: the test is about *when* `component` changes.
        globalThis.Vue = {
            shallowRef: (value) => value,
            defineAsyncComponent: (loader) => ({ __async: loader }),
        };
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

    it('runs the progress bar for a fetch and not for an inlined payload', async () => {
        const start = vi.fn();
        const done = vi.fn();

        const state = bind(
            createPageComponent({
                http: { json: async (path) => payloadFor(path) },
                styles: noStyles,
                config: {},
                initial: { url: '/one', component: payloadFor('/one') },
                progress: { start, done },
            })
        );

        // The landing page is already in the document. There is no request to indicate.
        await state.visit('/one');
        expect(start).not.toHaveBeenCalled();

        await state.visit('/two');
        expect(start).toHaveBeenCalledTimes(1);
        expect(done).toHaveBeenCalledTimes(1);
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

    it('wraps the page in the configured transition', () => {
        const page = createPageComponent({
            http: { json: async () => ({}) },
            styles: noStyles,
            config: {},
            initial: null,
            transition: 'my-fade',
        });

        expect(page.template).toContain('name="my-fade"');
        // Sequential, not overlapping: two pages in the DOM at once is a layout jump,
        // which is the thing being fixed.
        expect(page.template).toContain('mode="out-in"');
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
