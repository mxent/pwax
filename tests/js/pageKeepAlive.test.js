/**
 * Whether going back returns to the page, or to a fresh copy of it.
 *
 * The payload cache removes the round trip; `<KeepAlive>` is what removes the *remount*.
 * Without it a visitor who half-fills a form, follows a link and comes back finds an empty
 * form — the navigation was fast, and everything they had typed was still gone.
 *
 * These mount a real Vue application rather than inspecting the template string, because
 * the mechanism has a failure mode no string can show. Vue reuses a cached instance only
 * when the component *type object* is identical between the two visits — `patch()`
 * compares `n1.type === n2.type` — and every compile produces a fresh object. A
 * `<KeepAlive>` in the template with a recompiled page inside it does not merely fail to
 * keep state; Vue throws. So the assertion has to be "the value the visitor typed is still
 * there", made against the real renderer.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as VueRuntime from 'vue';
import { createPageComponent } from '../../src/js/page.js';
import { createRestore } from '../../src/js/restore.js';
import { resetModuleCache, setImporter } from '../../src/js/modules.js';

const noStyles = {
    link: async () => {},
    script: async () => {},
    acquire: () => {},
    release: () => {},
};

/**
 * A page with one input, so "did the instance survive" has a visible answer — and a mount
 * counter, so it has an unambiguous one.
 *
 * The visible value alone is not enough. When the swap throws, the previous DOM is left on
 * screen, so an assertion that only reads the input can pass because *nothing happened* —
 * which is how the first version of this file went green against an implementation that
 * crashed on every restore. `mounts` distinguishes "the instance was kept" from "the page
 * never changed": a retained page mounts exactly once, however many times it is visited.
 */
const formPage = (name, mounts, extra = {}) => ({
    data() {
        return { typed: '' };
    },
    mounted() {
        mounts[name] = (mounts[name] || 0) + 1;
    },
    template: `<div><span class="who">${name}</span><input class="field" v-model="typed"></div>`,
    ...extra,
});

/**
 * Mount `PwaxPage` inside a real application and drive it by calling `visit()`.
 *
 * The router is not involved: `visit()` is the whole navigation as far as this component
 * is concerned, and driving it directly keeps the test about the page slot rather than
 * about Vue Router.
 */
async function mountRuntime({ pages, restoreConfig = { entries: 12, state: true } } = {}) {
    const target = new EventTarget();
    const restore = createRestore(restoreConfig, target);

    /** name → how many times that page has been mounted. */
    const mounts = {};

    /** Anything Vue threw during render or patch. Must stay empty. */
    const thrown = [];

    // `created()` visits the component's own route, so there is always a page before the
    // one a test is interested in. Giving it a dedicated one keeps that out of the way.
    const all = { '/start': formPage('start', mounts), ...pages(mounts) };

    // Each path compiles to a distinct module, exactly as the real loader would. A path
    // with no entry *rejects*, which is how `/broken` produces a real navigation failure —
    // returning empty options instead would render nothing and set no error, and a test
    // written against that would be asserting on a page that merely looked blank.
    setImporter(
        vi.fn(async (url) => {
            if (!all[url]) {
                throw new Error(`no module for ${url}`);
            }

            return { default: all[url] };
        })
    );

    const page = createPageComponent({
        http: { json: vi.fn(async (path) => ({ module: path })) },
        styles: noStyles,
        config: { restore: restoreConfig },
        initial: null,
        restore,
    });

    const host = document.createElement('div');
    document.body.appendChild(host);

    let vm = null;
    const app = VueRuntime.createApp({
        render: () => VueRuntime.h(page, { ref: (r) => (vm = r) }),
    });

    // No router: `visit()` is the whole navigation as far as this component is concerned,
    // and driving it directly keeps the test about the page slot rather than Vue Router.
    app.config.globalProperties.$route = { fullPath: '/start' };
    app.config.globalProperties.$router = { push: vi.fn(), replace: vi.fn() };

    // A swap that throws leaves the previous page on screen, which reads exactly like a
    // swap that was never asked for. Recording it is what tells the two apart.
    app.config.errorHandler = (error) => thrown.push(error);

    app.mount(host);

    // Let the `created()` navigation settle before the test drives another, so the two
    // cannot interleave and leave the assertion racing.
    await vi.waitFor(() => {
        expect(host.querySelector('.who')?.textContent).toBe('start');
    });

    return {
        host,
        app,
        restore,
        mounts,
        thrown,
        back: () => target.dispatchEvent(new Event('popstate')),
        async go(path) {
            await vm.visit(path);
            await VueRuntime.nextTick();
        },
        field: () => host.querySelector('.field'),
        who: () => host.querySelector('.who')?.textContent,
        type(value) {
            const input = host.querySelector('.field');
            input.value = value;
            input.dispatchEvent(new Event('input'));
            return VueRuntime.nextTick();
        },
    };
}

describe('going back to a page you were part-way through', () => {
    beforeEach(() => {
        // The runtime reaches for a global Vue; hand it the real one.
        vi.stubGlobal('Vue', VueRuntime);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        resetModuleCache();
        document.body.innerHTML = '';
    });

    it('keeps what the visitor typed', async () => {
        const app = await mountRuntime({
            pages: (m) => ({ '/form': formPage('form', m), '/other': formPage('other', m) }),
        });

        await app.go('/form');
        await app.type('half a sentence');
        expect(app.field().value).toBe('half a sentence');

        await app.go('/other');
        expect(app.who()).toBe('other');

        app.back();
        await app.go('/form');

        expect(app.thrown).toEqual([]);
        expect(app.who()).toBe('form');
        expect(app.field().value).toBe('half a sentence');

        // The instance was reused, not rebuilt — which is the only reason the value is
        // still there, and is what a stale-DOM false pass would not show.
        expect(app.mounts.form).toBe(1);
    });

    it('does not keep it for a page that opted out', async () => {
        const app = await mountRuntime({
            pages: (m) => ({
                '/checkout': formPage('checkout', m, { restore: false }),
                '/other': formPage('other', m),
            }),
        });

        await app.go('/checkout');
        await app.type('card number');

        await app.go('/other');
        app.back();
        await app.go('/checkout');

        expect(app.thrown).toEqual([]);
        expect(app.who()).toBe('checkout');
        expect(app.field().value).toBe('');
        expect(app.mounts.checkout).toBe(2);
    });

    it('does not keep it when state retention is switched off', async () => {
        const app = await mountRuntime({
            pages: (m) => ({ '/form': formPage('form', m), '/other': formPage('other', m) }),
            restoreConfig: { entries: 12, state: false },
        });

        await app.go('/form');
        await app.type('half a sentence');

        await app.go('/other');
        app.back();
        await app.go('/form');

        // The round trip is still saved; the instance is not.
        expect(app.thrown).toEqual([]);
        expect(app.who()).toBe('form');
        expect(app.field().value).toBe('');
        expect(app.mounts.form).toBe(2);
    });

    it('renders the page after an opted-out page without losing the others', async () => {
        // The error and opt-out slots sit beside `<KeepAlive>` rather than replacing it,
        // so passing through a page that is not retained must not empty the cache.
        const app = await mountRuntime({
            pages: (m) => ({
                '/form': formPage('form', m),
                '/checkout': formPage('checkout', m, { restore: false }),
            }),
        });

        await app.go('/form');
        await app.type('kept');

        await app.go('/checkout');
        expect(app.who()).toBe('checkout');

        app.back();
        await app.go('/form');

        expect(app.thrown).toEqual([]);
        expect(app.field().value).toBe('kept');
        expect(app.mounts.form).toBe(1);
    });

    it('survives a failed navigation in between', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const app = await mountRuntime({
            pages: (m) => ({ '/form': formPage('form', m) }),
        });

        await app.go('/form');
        await app.type('still here');

        // A page that will not compile: the error screen renders, and it must not take
        // `<KeepAlive>` down with it.
        await app.go('/broken');
        expect(app.host.querySelector('.pwax-error')).not.toBeNull();
        expect(app.field()).toBeNull();

        app.back();
        await app.go('/form');

        expect(app.thrown).toEqual([]);
        expect(app.field().value).toBe('still here');
        expect(app.mounts.form).toBe(1);
    });
});
