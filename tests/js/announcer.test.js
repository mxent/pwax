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

function announcerFor(title, { mount = true } = {}) {
    document.body.innerHTML =
        (mount ? '<div id="pwax" tabindex="-1"></div>' : '') +
        '<div id="pwax-announcer" role="status" aria-live="polite"></div>';
    document.title = title;

    const page = createPageComponent({
        http: { json: async () => ({}) },
        styles: {},
        config: {},
        initial: null,
    });

    // Bound, because announcing and moving focus are two halves of the same job and
    // `announce()` calls the other one.
    const state = page.data();

    for (const [name, fn] of Object.entries(page.methods)) {
        state[name] = fn.bind(state);
    }

    return {
        announce: () => state.announce(),
        read: () => document.getElementById('pwax-announcer').textContent,
        focused: () => document.activeElement,
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
        const subject = announcerFor('Dashboard', { mount: false });
        document.body.innerHTML = '';

        expect(() => {
            subject.announce();
            subject.announce();
        }).not.toThrow();
    });
});

/**
 * Where focus lands after a client-side navigation.
 *
 * The other half of what a full page load does for free. A router leaves focus wherever
 * the link the visitor followed used to be — and once that link has been swapped out, on
 * the body. The next Tab then starts from the top of the document and the next
 * screen-reader command reads from wherever the cursor was stranded.
 */
describe('focus after a navigation', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('leaves focus alone on the first paint', () => {
        const subject = announcerFor('Dashboard');

        subject.announce();

        // The browser has just done this itself. Doing it again would steal focus from a
        // visitor who has already started interacting.
        expect(subject.focused()).toBe(document.body);
    });

    it('moves focus to the application root on a later navigation', () => {
        const subject = announcerFor('Dashboard');

        subject.announce();
        subject.announce();

        expect(subject.focused()).toBe(document.getElementById('pwax'));
    });

    it('does not take focus back from the new page', () => {
        const subject = announcerFor('Dashboard');

        subject.announce();

        // A page that focuses a search field in `mounted()` meant to; `mounted()` has
        // already run by the time this fires.
        const input = document.createElement('input');
        document.getElementById('pwax').appendChild(input);
        input.focus();

        subject.announce();

        expect(subject.focused()).toBe(input);
    });

    it('survives a shell with no mount element', () => {
        const subject = announcerFor('Dashboard', { mount: false });

        expect(() => {
            subject.announce();
            subject.announce();
        }).not.toThrow();
    });
});
