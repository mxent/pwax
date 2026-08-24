import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createRouter } from '../../src/js/router.js';

/**
 * The router is a thin wrapper: one catch-all route, and a `scrollBehavior` that decides
 * three things. `scrollBehavior` is the part with a decision in it, so it is what is
 * asserted here — called directly, because driving it through a real navigation would be
 * testing Vue Router rather than this file.
 */
describe('createRouter', () => {
    let scrollBehavior;

    beforeEach(() => {
        globalThis.VueRouter = {
            createWebHistory: vi.fn(() => ({ kind: 'history' })),
            createWebHashHistory: vi.fn(() => ({ kind: 'hash' })),
            createRouter: vi.fn((options) => {
                scrollBehavior = options.scrollBehavior;
                return { options };
            }),
        };
    });

    it('throws a readable error when Vue Router is not loaded', () => {
        delete globalThis.VueRouter;

        expect(() => createRouter({ page: {}, config: {} })).toThrow(/Vue Router is not loaded/);
    });

    it('uses hash history only when the server asked for it', () => {
        createRouter({ page: {}, config: { hashRouting: true } });
        expect(globalThis.VueRouter.createWebHashHistory).toHaveBeenCalled();

        createRouter({ page: {}, config: { base: '/app/' } });
        expect(globalThis.VueRouter.createWebHistory).toHaveBeenCalledWith('/app/');
    });

    it('restores the saved position on back and forward', () => {
        createRouter({ page: {}, config: {} });

        expect(scrollBehavior({ hash: '#x' }, {}, { top: 120 })).toEqual({ top: 120 });
    });

    it('goes to the top of a page with no fragment', () => {
        createRouter({ page: {}, config: {} });

        expect(scrollBehavior({ hash: '' }, {}, null)).toEqual({ top: 0 });
    });

    it('scrolls smoothly to a fragment', () => {
        window.matchMedia = vi.fn(() => ({ matches: false }));
        createRouter({ page: {}, config: {} });

        expect(scrollBehavior({ hash: '#section' }, {}, null)).toEqual({
            el: '#section',
            behavior: 'smooth',
        });
    });

    /*
     * A smooth scroll is animated motion across the whole viewport, and no stylesheet can
     * turn it off — it is driven from JavaScript, so the query has to be asked in code.
     */
    it('jumps rather than scrolls when the visitor asked for less motion', () => {
        window.matchMedia = vi.fn(() => ({ matches: true }));
        createRouter({ page: {}, config: {} });

        expect(scrollBehavior({ hash: '#section' }, {}, null)).toEqual({
            el: '#section',
            behavior: 'auto',
        });
    });

    it('falls back to jumping where matchMedia does not exist', () => {
        delete window.matchMedia;
        createRouter({ page: {}, config: {} });

        expect(scrollBehavior({ hash: '#section' }, {}, null)).toEqual({
            el: '#section',
            behavior: 'auto',
        });
    });
});
