/**
 * The client half of `php artisan pwax:compile`.
 *
 * A page rendered with controller data is not addressable by view name, so it ships its
 * script inline rather than at a URL. Its precompiled render function therefore has to
 * travel *inside* that script: the client evaluates the script as a module, so the module
 * loader produces the function, and nothing has to call `new Function` on a string — which
 * is the one construct the whole mode exists to remove.
 *
 * These mount such a payload under a Vue that has no compiler, which is what
 * `assets.vue_build => 'runtime'` actually serves.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPageComponent } from '../../src/js/page.js';
import { resetModuleCache, setImporter } from '../../src/js/modules.js';

const noStyles = {
    link: async () => {},
    script: async () => {},
    acquire: () => {},
    release: () => {},
};

function bind(page) {
    const state = {
        ...page.data(),
        $route: { fullPath: '/one' },
        $router: { replace: vi.fn(), push: vi.fn() },
        // Vue's own `$nextTick` runs a callback *and*, called bare, returns a promise.
        // Stubbing only the callback form left `await this.$nextTick()` calling
        // `undefined()`, and the page component reporting a connection failure.
        $nextTick: (fn) => (fn ? fn() : Promise.resolve()),
    };

    for (const [name, fn] of Object.entries(page.methods)) {
        state[name] = fn.bind(state);
    }

    return state;
}

/** A page component mounting exactly one payload. */
function pageFor(payload) {
    return bind(
        createPageComponent({
            http: { json: async () => payload },
            styles: noStyles,
            config: {},
            initial: null,
        })
    );
}

/**
 * The error the page actually threw.
 *
 * `state.error` is what the visitor is shown, and for anything that is not an HTTP status
 * that is deliberately "this page needs an internet connection" — true for a dropped
 * connection, useless for a misconfiguration. The raw error goes to the `pwax:error` event
 * and to the console, which is where a developer can find it.
 */
function thrownBy(state, path) {
    let thrown = null;
    const listen = (event) => (thrown = event.detail.error);

    document.addEventListener('pwax:error', listen);

    return state.visit(path).then(() => {
        document.removeEventListener('pwax:error', listen);

        return thrown;
    });
}

describe('a precompiled page under the runtime-only Vue build', () => {
    const render = () => null;

    beforeEach(() => {
        resetModuleCache();
        vi.spyOn(console, 'error').mockImplementation(() => {});

        // No `compile`. This is the shape of `vue.runtime.global.prod.js`, which is what
        // an application that has run `pwax:compile` is being served.
        globalThis.Vue = { markRaw: (value) => value };
    });

    afterEach(() => {
        delete globalThis.Vue;
        vi.restoreAllMocks();
    });

    it('mounts using the render function carried in the inline script', async () => {
        // What the server emits: the render bindings, then the author's own script.
        setImporter(vi.fn().mockResolvedValue({ __pwaxRender: render, default: { name: 'Home' } }));

        const state = pageFor({
            hash: 'abc',
            script: 'const __pwaxRender = () => null; export { __pwaxRender };',
            template: '<p>home</p>',
        });

        await state.visit('/one');

        expect(state.error).toBeNull();
        expect(state.component.render).toBe(render);

        // The template is not carried forward. Vue prefers `render` when both are set, so
        // this is not a correctness bug either way — it is bytes and ambiguity.
        expect(state.component.template).toBeUndefined();
    });

    /**
     * The failure this replaces is Vue's: a console warning and an empty element, on a page
     * that is blank with nothing to say which deploy step was skipped.
     */
    it('fails by name when a page was never compiled', async () => {
        const state = pageFor({ template: '<p>home</p>' });

        expect((await thrownBy(state, '/one')).message).toMatch(/pwax:compile/);
        expect(state.error).not.toBeNull();
    });

    it('fails by name when an imported component was never compiled', async () => {
        setImporter(vi.fn().mockResolvedValue({ default: {}, __pwaxTemplate: '<p>c</p>' }));

        const state = pageFor({ module: '/__pwax__/c/abc.js' });

        expect((await thrownBy(state, '/one')).message).toMatch(/vue_build/);
        expect(state.error).not.toBeNull();
    });

    /**
     * Shown as a connection problem, which is what the visitor can act on — but the real
     * cause has to reach the console, or the only symptom of a skipped deploy step is a
     * page claiming the network is down.
     */
    it('reports the real cause to the console', async () => {
        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

        await pageFor({ template: '<p>home</p>' }).visit('/one');

        expect(spy).toHaveBeenCalledWith(
            'pwax: navigation failed.',
            expect.objectContaining({ message: expect.stringMatching(/pwax:compile/) })
        );
    });

    /**
     * The same payloads under the default build, where the template is the answer and
     * nothing should be complaining about anything.
     */
    it('renders an uncompiled template happily under the full build', async () => {
        globalThis.Vue = { markRaw: (value) => value, compile: () => () => null };

        const state = pageFor({ template: '<p>home</p>' });

        await state.visit('/one');

        expect(state.error).toBeNull();
        expect(state.component.template).toBe('<p>home</p>');
    });
});
