import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Tests for `window.pwax.start()` — the reboot API.
 *
 * The full `boot()` in `index.js` imports the entire runtime, so these tests mock the
 * globals it needs (Vue, Pinia, VueRouter) and the JSON islands it reads. The goal is
 * not to re-test the boot sequence — the other test files cover each piece — but to
 * verify that `start` is exposed, that it unmounts the previous app, and that a fresh
 * boot re-arms it.
 */

function island(id, contents) {
    const el = document.createElement('script');
    el.type = 'application/json';
    el.id = id;
    el.textContent = contents;
    document.body.appendChild(el);
}

function stubVue() {
    const app = {
        use: vi.fn(),
        mount: vi.fn(),
        unmount: vi.fn(),
    };

    const Vue = {
        createApp: vi.fn(() => app),
        markRaw: vi.fn((v) => v),
        defineAsyncComponent: vi.fn((loader) => ({ __asyncLoader: loader })),
    };

    vi.stubGlobal('Vue', Vue);

    return { Vue, app };
}

function stubRouter() {
    const router = {
        isReady: vi.fn().mockResolvedValue(undefined),
        beforeEach: vi.fn(),
    };

    const VueRouter = {
        createWebHistory: vi.fn(() => ({})),
        createRouter: vi.fn(() => router),
    };

    vi.stubGlobal('VueRouter', VueRouter);

    return router;
}

function stubPinia() {
    const Pinia = { createPinia: vi.fn(() => ({})) };
    vi.stubGlobal('Pinia', Pinia);
}

async function importRuntime() {
    // Reset the module cache so each test re-executes index.js from scratch — the module
    // auto-runs `start()` on import, and a cached module would not.
    vi.resetModules();
    return import('../../src/js/index.js');
}

describe('window.pwax.start (reboot)', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        document.body.innerHTML = '';
        vi.unstubAllGlobals();

        // The mount element boot() looks for.
        document.body.innerHTML = '<div id="pwax" class="pwax-preloader"></div>';

        island('pwax-config', JSON.stringify({ mount: 'pwax' }));

        stubVue();
        stubRouter();
        stubPinia();
    });

    afterEach(() => {
        delete window.pwax;
        vi.unstubAllGlobals();
    });

    it('exposes start as a function on window.pwax after boot', async () => {
        await importRuntime();

        // The module sets things up on DOMContentLoaded or immediately; give it a tick.
        await vi.waitFor(() => {
            expect(window.pwax).toBeDefined();
            expect(typeof window.pwax.start).toBe('function');
        });
    });

    it('unmounts the previous app when start is called', async () => {
        const { app } = stubVue();
        await importRuntime();

        await vi.waitFor(() => {
            expect(window.pwax).toBeDefined();
        });

        await window.pwax.start();

        expect(app.unmount).toHaveBeenCalled();
    });

    it('re-arms start after a reboot so it can be called again', async () => {
        await importRuntime();

        await vi.waitFor(() => {
            expect(window.pwax).toBeDefined();
        });

        await window.pwax.start();

        expect(typeof window.pwax.start).toBe('function');
    });
});