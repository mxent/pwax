/**
 * What a screen reader is told.
 *
 * Two defects, both of them the kind that never show up in a browser and never stop
 * showing up for someone using assistive technology.
 *
 * The mount element carried `role="status"` and `aria-live="polite"` so the spinner would
 * announce itself, and the runtime removed only the class on mount. Every reactive text
 * change anywhere in the application was then announced for the rest of the session, and
 * the application root was permanently labelled "Loading".
 *
 * And a router change announced nothing at all. A full navigation announces itself; this
 * is the part an SPA has to put back.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { createPageComponent } from '../../src/js/page.js';

function announcerFor(title) {
    document.body.innerHTML = '<div id="pwax-announcer" role="status" aria-live="polite"></div>';
    document.title = title;

    const page = createPageComponent({
        http: { json: async () => ({}) },
        styles: {},
        config: {},
        initial: null,
    });

    const state = page.data();

    return {
        announce: () => page.methods.announce.call(state),
        read: () => document.getElementById('pwax-announcer').textContent,
    };
}

describe('announcing a navigation', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('says nothing on the first paint', () => {
        const subject = announcerFor('Dashboard');

        // The browser has just read this document. Repeating it is noise.
        subject.announce();

        expect(subject.read()).toBe('');
    });

    it('announces the new title on a later navigation', () => {
        const subject = announcerFor('Dashboard');

        subject.announce();
        document.title = 'Settings';
        subject.announce();

        expect(subject.read()).toBe('Settings');
    });

    it('survives two pages sharing a title', () => {
        const subject = announcerFor('Dashboard');

        subject.announce();
        document.title = 'Report';
        subject.announce();
        subject.announce();

        // A live region announces a change, not a value. Without clearing it first the
        // second visit is silent.
        expect(subject.read()).toBe('Report');
    });

    it('does nothing when the shell has no announcer', () => {
        // A published shell from an older version will not have one, and a missing
        // announcer must not break navigation.
        document.body.innerHTML = '';
        const subject = announcerFor('Dashboard');
        document.body.innerHTML = '';

        expect(() => {
            subject.announce();
            subject.announce();
        }).not.toThrow();
    });
});
