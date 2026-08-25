/**
 * What the page component does with the back button.
 *
 * `restore.js` decides *whether* a payload may be served; these assert that the page
 * component asks at the right moment and records at the right moment. The two failures
 * worth guarding are opposites: never asking, which leaves the back button as slow as it
 * was, and recording a page that never actually rendered, which would restore something
 * the visitor was never shown.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPageComponent } from '../../src/js/page.js';
import { createRestore } from '../../src/js/restore.js';
import { resetModuleCache, setImporter } from '../../src/js/modules.js';

const noStyles = {
    link: async () => {},
    script: async () => {},
    acquire: () => {},
    release: () => {},
};

/** `createPageComponent` returns plain options, so its methods bind to a hand-made state. */
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

function server() {
    return {
        json: vi.fn(async (path) => ({ template: `<p>${path}</p>` })),
    };
}

/** The page component, its store, and the `popstate` target that drives restoration. */
function runtime({ http = server(), progress = null, initial = null, middleware = {} } = {}) {
    const target = new EventTarget();
    const restore = createRestore({}, target);

    const page = createPageComponent({
        http,
        styles: noStyles,
        config: {},
        initial,
        restore,
        progress,
        middleware,
    });

    return {
        http,
        restore,
        state: bind(page),
        back: () => target.dispatchEvent(new Event('popstate')),
    };
}

describe('going back to a page the document has already rendered', () => {
    beforeEach(() => {
        globalThis.Vue = { markRaw: (value) => value, compile: () => () => null };
    });

    afterEach(() => {
        delete globalThis.Vue;
        resetModuleCache();
    });

    it('renders it from memory instead of asking the server again', async () => {
        const { http, state, back } = runtime();

        await state.visit('/one');
        await state.visit('/two');
        expect(http.json).toHaveBeenCalledTimes(2);

        back();
        await state.visit('/one');

        expect(http.json).toHaveBeenCalledTimes(2);
        expect(state.component.template).toBe('<p>/one</p>');
        expect(state.renderedPath).toBe('/one');
    });

    it('still fetches when a link is clicked to a page already held', async () => {
        const { http, state } = runtime();

        await state.visit('/one');
        await state.visit('/two');

        // No pop. Clicking a link asks for the page as it is now.
        await state.visit('/one');

        expect(http.json).toHaveBeenCalledTimes(3);
    });

    it('never shows the loader or moves the progress bar, because there is no wait', async () => {
        const progress = { start: vi.fn(), done: vi.fn() };
        const { state, back } = runtime({ progress });

        await state.visit('/one');
        await state.visit('/two');
        progress.start.mockClear();

        back();
        await state.visit('/one');

        expect(progress.start).not.toHaveBeenCalled();
        expect(state.loading).toBe(false);
    });

    it('keeps the page that was inlined into the document', async () => {
        const initial = { url: '/one', component: { template: '<p>inlined</p>' } };
        const { http, state, back } = runtime({ initial });

        // First paint costs no request, and is recorded like any other page.
        await state.visit('/one');
        expect(http.json).not.toHaveBeenCalled();

        await state.visit('/two');
        expect(http.json).toHaveBeenCalledTimes(1);

        back();
        await state.visit('/one');

        expect(http.json).toHaveBeenCalledTimes(1);
        expect(state.component.template).toBe('<p>inlined</p>');
    });

    it('records nothing for a navigation that failed', async () => {
        const http = {
            json: vi.fn(async () => {
                throw new Error('offline');
            }),
        };
        const { state, restore, back } = runtime({ http });

        await state.visit('/one');
        expect(state.error).not.toBeNull();
        expect(restore.size).toBe(0);

        // And going back to it is a fetch, not a restored error screen.
        back();
        await state.visit('/one');
        expect(http.json).toHaveBeenCalledTimes(2);
    });

    it('records nothing for a payload that arrived but would not compile', async () => {
        // The fetch succeeded, so a store written when the payload lands rather than when
        // the page renders would keep this one — and restore an error screen later.
        vi.spyOn(console, 'error').mockImplementation(() => {});
        setImporter(vi.fn().mockRejectedValue(new Error('bad module')));

        const { state, restore } = runtime();

        state.currentPath = '/broken';
        await state.mount({ module: '/components/broken.js' });

        expect(state.error).not.toBeNull();
        expect(restore.size).toBe(0);
    });

    it('records nothing for a page a middleware redirected away from', async () => {
        setImporter(
            vi.fn().mockResolvedValue({
                default: { template: '<p>private</p>', middleware: ['auth'] },
            })
        );

        const { state, restore } = runtime({
            middleware: { auth: ({ redirect }) => redirect('/login') },
        });

        state.currentPath = '/private';
        await state.mount({ module: '/components/private.js' });

        // The visitor never saw this page, so there is nothing to go back to.
        expect(restore.size).toBe(0);
    });

    it('leaves a page out when its component asks to be left out', async () => {
        setImporter(
            vi.fn().mockResolvedValue({
                default: { template: '<p>once</p>', restore: false },
            })
        );

        const { state, restore } = runtime();

        state.currentPath = '/checkout';
        await state.mount({ module: '/components/checkout.js' });

        expect(state.component.template).toBe('<p>once</p>');
        expect(restore.size).toBe(0);
    });

    it('keeps a page whose component says nothing about it', async () => {
        setImporter(vi.fn().mockResolvedValue({ default: { template: '<p>ordinary</p>' } }));

        const { state, restore } = runtime();

        state.currentPath = '/ordinary';
        await state.mount({ module: '/components/ordinary.js' });

        expect(restore.size).toBe(1);
    });

    it('drops a page an application says is now wrong', async () => {
        const { http, state, restore, back } = runtime();

        await state.visit('/one');
        await state.visit('/two');

        // What `window.pwax.restore.forget()` does after a mutation.
        restore.forget('/one');

        back();
        await state.visit('/one');

        expect(http.json).toHaveBeenCalledTimes(3);
    });
});
