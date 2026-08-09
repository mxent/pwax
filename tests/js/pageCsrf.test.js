/**
 * An expired CSRF token, and the reload that is supposed to fix it.
 *
 * The token is baked into the document, so it cannot be refreshed in place — the page has
 * to be reloaded. That assumes the reload reaches the server, which it does not under
 * `navigation_strategy => 'app-shell'`: the worker answers navigations from disk, the same
 * stale token comes back, and the page reloads forever. Now that a navigation's HTML is
 * also cached as it is visited, there are more documents that can carry a dead token.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { HttpError } from '../../src/js/http.js';
import { createPageComponent } from '../../src/js/page.js';

/** A page whose payload fetch always fails the way an expired token does. */
function expired() {
    return createPageComponent({
        http: {
            json: async () => {
                throw new HttpError({ status: 419, statusText: 'Page Expired' }, null);
            },
        },
        styles: {},
        config: {},
        initial: null,
    });
}

/** Enough of the component instance for `visit()` to run against. */
function instance(page) {
    return {
        controller: null,
        error: null,
        loading: false,
        currentPath: null,
        abort: page.methods.abort,
        mount: vi.fn(),
        fail: vi.fn(),
    };
}

describe('an expired CSRF token', () => {
    let reload;

    beforeEach(() => {
        reload = vi.fn();
        // jsdom's location.reload is not writable; replacing the object is the usual way.
        delete window.location;
        window.location = { reload, href: 'http://localhost/' };
        window.sessionStorage.clear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('reloads to pick up a fresh one', async () => {
        const page = expired();
        const self = instance(page);

        await page.methods.visit.call(self, '/about');

        expect(reload).toHaveBeenCalledTimes(1);
        expect(self.fail).not.toHaveBeenCalled();
    });

    it('reloads once, then shows the error like any other', async () => {
        const page = expired();

        // The second attempt is what a reload answered from cache produces: same document,
        // same dead token, same 419. Without the guard this is an endless reload.
        await page.methods.visit.call(instance(page), '/about');

        const second = instance(page);
        await page.methods.visit.call(second, '/about');

        expect(reload).toHaveBeenCalledTimes(1);
        expect(second.fail).toHaveBeenCalledTimes(1);
        expect(second.fail.mock.calls[0][0].status).toBe(419);
    });

    it('re-arms once a page loads', async () => {
        const page = expired();

        await page.methods.visit.call(instance(page), '/about');

        // A payload arriving means this document's token is being accepted, so a later
        // expiry — a session that timed out while the tab was open — can reload again.
        const working = createPageComponent({
            http: { json: async () => ({ template: '<p>ok</p>' }) },
            styles: {},
            config: {},
            initial: null,
        });

        await working.methods.visit.call(instance(working), '/about');
        await page.methods.visit.call(instance(page), '/about');

        expect(reload).toHaveBeenCalledTimes(2);
    });
});
