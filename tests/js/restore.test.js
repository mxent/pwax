/**
 * Serving a page from memory when the visitor goes back to it.
 *
 * The rule these exist to pin down is the one that makes the cache safe: a payload is
 * served for a navigation the *browser* started, and never for one the application
 * started. Get that wrong in the permissive direction and clicking a link shows a stale
 * page; get it wrong in the strict direction and the back button is no faster than before.
 *
 * The flag that carries it is consumed once per navigation, which is the part most easily
 * broken by a later change — a pop that misses the cache must not leave the flag set for
 * the click that follows it.
 *
 * The stored values here are stand-ins. In the runtime each entry is a `{payload, options}`
 * pair — the store is deliberately incurious about that, and these are about which entry
 * comes back and when, not what is in it.
 */
import { afterEach, describe, expect, it } from 'vitest';
import { createRestore } from '../../src/js/restore.js';

/** A `popstate` target that is not the real window, so tests cannot leak into each other. */
function history() {
    const target = new EventTarget();

    return {
        target,
        /** What the browser does on back, forward, or `router.go()`. */
        pop: () => target.dispatchEvent(new Event('popstate')),
        listeners: () => target,
    };
}

describe('restoring a page the visitor has already seen', () => {
    let restore;

    afterEach(() => {
        restore?.stop();
        restore = null;
    });

    it('serves a remembered page when the browser started the navigation', () => {
        const nav = history();
        restore = createRestore({}, nav.target);

        restore.remember('/one', { template: '<p>one</p>' });
        nav.pop();

        expect(restore.take('/one')).toEqual({ template: '<p>one</p>' });
    });

    it('serves nothing when the application started the navigation', () => {
        const nav = history();
        restore = createRestore({}, nav.target);

        restore.remember('/one', { template: '<p>one</p>' });

        // No pop: this is a link click. Clicking a link asks for the page as it is now.
        expect(restore.take('/one')).toBeNull();
    });

    it('consumes the pop, so a miss cannot make the next click a restoration', () => {
        const nav = history();
        restore = createRestore({}, nav.target);

        restore.remember('/two', { template: '<p>two</p>' });

        nav.pop();

        // Back to a page that was never rendered in this document — a fetch.
        expect(restore.take('/never-seen')).toBeNull();

        // The click that follows must not be answered from the store.
        expect(restore.take('/two')).toBeNull();
    });

    it('keeps a page for every later visit back to it', () => {
        const nav = history();
        restore = createRestore({}, nav.target);

        restore.remember('/one', { template: '<p>one</p>' });

        nav.pop();
        expect(restore.take('/one')).not.toBeNull();

        // Unlike a prefetch, which is spent by the navigation it was made for.
        nav.pop();
        expect(restore.take('/one')).not.toBeNull();
    });

    it('drops the least recently used page once the cap is reached', () => {
        const nav = history();
        restore = createRestore({ entries: 2 }, nav.target);

        restore.remember('/a', { n: 1 });
        restore.remember('/b', { n: 2 });
        restore.remember('/c', { n: 3 });

        expect(restore.size).toBe(2);

        nav.pop();
        expect(restore.take('/a')).toBeNull();
        nav.pop();
        expect(restore.take('/c')).toEqual({ n: 3 });
    });

    it('counts going back to a page as a use of it', () => {
        const nav = history();
        restore = createRestore({ entries: 2 }, nav.target);

        restore.remember('/a', { n: 1 });
        restore.remember('/b', { n: 2 });

        // Restoring `/a` should make `/b` the stale one, not `/a`.
        nav.pop();
        restore.take('/a');

        restore.remember('/c', { n: 3 });

        nav.pop();
        expect(restore.take('/a')).toEqual({ n: 1 });
        nav.pop();
        expect(restore.take('/b')).toBeNull();
    });

    it('re-remembering a page does not grow the store', () => {
        const nav = history();
        restore = createRestore({}, nav.target);

        restore.remember('/a', { n: 1 });
        restore.remember('/a', { n: 2 });

        expect(restore.size).toBe(1);

        nav.pop();
        expect(restore.take('/a')).toEqual({ n: 2 });
    });

    it('forgets one page on request, for an application that has just made it wrong', () => {
        const nav = history();
        restore = createRestore({}, nav.target);

        restore.remember('/a', { n: 1 });
        restore.remember('/b', { n: 2 });

        restore.forget('/a');

        nav.pop();
        expect(restore.take('/a')).toBeNull();
        nav.pop();
        expect(restore.take('/b')).toEqual({ n: 2 });
    });

    it('forgets everything on request, for a sign-out', () => {
        const nav = history();
        restore = createRestore({}, nav.target);

        restore.remember('/a', { n: 1 });
        restore.remember('/b', { n: 2 });

        restore.clear();

        expect(restore.size).toBe(0);
    });

    describe('when it is switched off', () => {
        it('does nothing at all for `false`', () => {
            const nav = history();
            restore = createRestore(false, nav.target);

            restore.remember('/a', { n: 1 });
            nav.pop();

            expect(restore.enabled).toBe(false);
            expect(restore.size).toBe(0);
            expect(restore.take('/a')).toBeNull();
        });

        it('does nothing at all for `enabled: false`', () => {
            const nav = history();
            restore = createRestore({ enabled: false }, nav.target);

            restore.remember('/a', { n: 1 });
            nav.pop();

            expect(restore.enabled).toBe(false);
            expect(restore.take('/a')).toBeNull();
        });

        it('reads a cap of zero as off rather than as unbounded', () => {
            const nav = history();
            restore = createRestore({ entries: 0 }, nav.target);

            restore.remember('/a', { n: 1 });

            expect(restore.enabled).toBe(false);
            expect(restore.size).toBe(0);
        });

        it('does not listen for pops', () => {
            const nav = history();
            let popped = false;

            restore = createRestore(false, nav.target);
            nav.target.addEventListener('popstate', () => {
                popped = true;
            });

            nav.pop();

            // The event was dispatched — the cache simply is not listening for it.
            expect(popped).toBe(true);
            expect(restore.take('/a')).toBeNull();
        });
    });

    it('stops listening when torn down, so a reboot does not leave two of them', () => {
        const nav = history();
        restore = createRestore({}, nav.target);

        restore.remember('/a', { n: 1 });
        restore.stop();

        nav.pop();

        // Neither the flag nor the store survived.
        expect(restore.take('/a')).toBeNull();
        expect(restore.size).toBe(0);
    });
});
