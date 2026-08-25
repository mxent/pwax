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
const formPage = (name, log, extra = {}) => ({
    data() {
        return { typed: '' };
    },
    mounted() {
        log.mounts[name] = (log.mounts[name] || 0) + 1;
    },
    // Which of these fires on the way out is the whole difference between the two page
    // slots: a retained page is *deactivated* and kept, one that is not is *unmounted* and
    // destroyed. Nothing else distinguishes them from outside.
    deactivated() {
        log.events.push(`${name}:deactivated`);
    },
    unmounted() {
        log.events.push(`${name}:unmounted`);
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

    /** `mounts`: name → times mounted. `events`: lifecycle in the order it happened. */
    const log = { mounts: {}, events: [] };
    const mounts = log.mounts;

    /** Anything Vue threw during render or patch. Must stay empty. */
    const thrown = [];

    // `created()` visits the component's own route, so there is always a page before the
    // one a test is interested in. Giving it a dedicated one keeps that out of the way.
    const all = { '/start': formPage('start', log), ...pages(log) };

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
        // `mount` names the element the runtime owns; the scroll capture reads the
        // offsets from inside it, the same element `refocus()` reaches for.
        config: { mount: 'pwax', restore: restoreConfig },
        initial: null,
        restore,
    });

    const host = document.createElement('div');
    host.id = 'pwax';
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
        events: log.events,
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

    it('destroys an opted-out page rather than parking it in the cache', async () => {
        /*
         * The visible half of the opt-out — a fresh, empty form on return — is enforced by
         * the store alone: an opted-out page is never remembered, so it recompiles, gets a
         * new key, and `<KeepAlive>` cannot mistake it for the instance it already has.
         *
         * That is not the whole of what `restore: false` promises. A checkout step or a
         * page holding a one-time token should not be *sitting in memory* with the
         * visitor's input still in its DOM, whether or not it is ever shown again. Which
         * is what the second slot beside `<KeepAlive>` is for, and the only way to see the
         * difference from outside is which lifecycle hook fires on the way out.
         */
        const app = await mountRuntime({
            pages: (m) => ({
                '/checkout': formPage('checkout', m, { restore: false }),
                '/form': formPage('form', m),
                '/other': formPage('other', m),
            }),
        });

        await app.go('/form');
        await app.go('/other');

        // A retained page is parked, not destroyed.
        expect(app.events).toContain('form:deactivated');
        expect(app.events).not.toContain('form:unmounted');

        await app.go('/checkout');
        await app.type('card number');
        await app.go('/other');

        // An opted-out one is destroyed, and takes what was typed into it with it.
        expect(app.events).toContain('checkout:unmounted');
        expect(app.events).not.toContain('checkout:deactivated');
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

    it('rebuilds the page cleanly after an application drops it', async () => {
        // `window.pwax.restore.forget()` is the documented way to say a held page is now
        // wrong. Dropping the payload makes the next visit fetch and compile afresh — and
        // the instance Vue still has cached under that key was built from the *previous*
        // compile.
        const app = await mountRuntime({
            pages: (m) => ({ '/form': formPage('form', m), '/other': formPage('other', m) }),
        });

        await app.go('/form');
        await app.type('stale');
        await app.go('/other');

        app.restore.forget('/form');

        app.back();
        await app.go('/form');

        expect(app.thrown).toEqual([]);
        expect(app.who()).toBe('form');
        // Dropped on purpose, so the visitor gets a fresh page rather than the old one.
        expect(app.field().value).toBe('');
        expect(app.mounts.form).toBe(2);
    });

    it('rebuilds every page cleanly after an application clears the lot', async () => {
        const app = await mountRuntime({
            pages: (m) => ({ '/form': formPage('form', m), '/other': formPage('other', m) }),
        });

        await app.go('/form');
        await app.type('stale');
        await app.go('/other');

        // What an application does on sign-out.
        app.restore.clear();

        app.back();
        await app.go('/form');

        expect(app.thrown).toEqual([]);
        expect(app.field().value).toBe('');
    });

    it('rebuilds a page cleanly after it is evicted for being the oldest', async () => {
        // The two caches evict on their own schedules, so a page can be gone from one
        // while the other still holds it, with nobody having called anything. This does
        // not by itself reproduce the crash the two tests above do — under a shared cap
        // the orderings usually agree — which is exactly why it is worth pinning: it
        // covers the ordinary path to the same divergence.
        const app = await mountRuntime({
            pages: (m) => ({
                '/a': formPage('a', m),
                '/b': formPage('b', m),
                '/c': formPage('c', m),
            }),
            restoreConfig: { entries: 2, state: true },
        });

        await app.go('/a');
        await app.type('dropped');
        await app.go('/b');
        await app.go('/c');

        app.back();
        await app.go('/a');

        expect(app.thrown).toEqual([]);
        expect(app.who()).toBe('a');
        expect(app.field().value).toBe('');
    });

    it('puts back the scroll offsets inside a retained page', async () => {
        /*
         * `<KeepAlive>` keeps the nodes and the browser keeps what is in them, but not
         * where they are scrolled to: deactivating detaches the nodes, and a scrollable
         * element leaving the document has its `scrollTop` reset by the browser.
         *
         * This test cannot prove that. jsdom has no layout, so `scrollTop` is an ordinary
         * property that survives a detach — every case here reads as already working
         * whether the offsets are put back or not. What it does pin is the wiring: that
         * the outgoing page's offsets are read *before* the swap, and applied to the
         * incoming one. Both halves matter and both were wrong at first — a
         * `deactivated()` hook reads zero because the nodes are already detached, and
         * offsets applied after the view transition starts are discarded when it ends.
         *
         * The behaviour itself was verified against the built runtime in headless
         * Chromium, with and without the View Transitions API, since that is the only
         * place it is observable at all.
         */
        const app = await mountRuntime({
            pages: (m) => ({ '/form': formPage('form', m), '/other': formPage('other', m) }),
        });

        await app.go('/form');

        const pane = document.createElement('div');
        app.host.querySelector('.who').after(pane);
        pane.className = 'pane';
        pane.scrollTop = 250;

        await app.go('/other');
        expect(app.who()).toBe('other');

        // What a real browser does when the nodes are detached, and what jsdom does not.
        // Without standing it in, this assertion passes against an implementation that
        // captures nothing and applies nothing — which is what the first version of this
        // test did.
        pane.scrollTop = 0;

        app.back();
        await app.go('/form');

        expect(app.thrown).toEqual([]);
        expect(app.host.querySelector('.pane')?.scrollTop).toBe(250);
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
