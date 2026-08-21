/**
 * Keeping the head in step with a client-side navigation.
 *
 * The failure this prevents is quiet: a router swaps the body and leaves the head alone,
 * so the description, canonical URL and Open Graph tags keep describing whichever page the
 * visitor landed on first. Nothing looks wrong on screen — it only shows up in a link
 * preview or a crawler, long after.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { applyHead } from '../../src/js/head.js';

const tag = (selector) => document.head.querySelector(selector);

describe('applying a page head', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        document.title = '';
    });

    it('does nothing at all when a page declares nothing', () => {
        document.head.innerHTML = '<meta name="description" content="site wide">';

        applyHead(undefined);

        // The application-wide description survives. Clearing it for every page that set
        // none would lose it on the first navigation.
        expect(tag('meta[name="description"]').getAttribute('content')).toBe('site wide');
    });

    it('sets the title and description', () => {
        applyHead({ title: 'Hello', description: 'A first post.' });

        expect(document.title).toBe('Hello');
        expect(tag('meta[name="description"]').getAttribute('content')).toBe('A first post.');
    });

    it('replaces a description rather than adding a second one', () => {
        document.head.innerHTML = '<meta name="description" content="old">';

        applyHead({ description: 'new' });

        expect(document.head.querySelectorAll('meta[name="description"]')).toHaveLength(1);
        expect(tag('meta[name="description"]').getAttribute('content')).toBe('new');
    });

    it('adds, updates and removes the canonical link', () => {
        applyHead({ canonical: 'https://example.test/a' });
        expect(tag('link[rel="canonical"]').getAttribute('href')).toBe('https://example.test/a');

        applyHead({ canonical: 'https://example.test/b' });
        expect(document.head.querySelectorAll('link[rel="canonical"]')).toHaveLength(1);
        expect(tag('link[rel="canonical"]').getAttribute('href')).toBe('https://example.test/b');

        // Removed, not left behind. One URL cannot be canonical for every route, and the
        // previous page's is exactly the wrong answer.
        applyHead({ title: 'No canonical here' });
        expect(tag('link[rel="canonical"]')).toBeNull();
    });

    it('replaces the previous page&apos;s managed tags', () => {
        applyHead({ meta: [{ attribute: 'property', key: 'og:title', content: 'First' }] });
        expect(tag('meta[property="og:title"]').getAttribute('content')).toBe('First');

        applyHead({ meta: [{ attribute: 'property', key: 'og:title', content: 'Second' }] });

        const all = document.head.querySelectorAll('meta[property="og:title"]');

        expect(all).toHaveLength(1);
        expect(all[0].getAttribute('content')).toBe('Second');
    });

    it('drops a tag the next page does not set', () => {
        applyHead({
            meta: [
                { attribute: 'name', key: 'robots', content: 'noindex' },
                { attribute: 'property', key: 'og:title', content: 'Draft' },
            ],
        });

        applyHead({ meta: [{ attribute: 'property', key: 'og:title', content: 'Published' }] });

        // A draft page's `noindex` following the visitor onto a published one is the
        // failure that matters here.
        expect(tag('meta[name="robots"]')).toBeNull();
        expect(tag('meta[property="og:title"]').getAttribute('content')).toBe('Published');
    });

    it("leaves the application's own head tags alone", () => {
        document.head.innerHTML =
            '<meta name="verification" content="mine"><meta property="og:title" content="managed" data-pwax-head>';

        applyHead({ meta: [] });

        // Anything pushed into @stack('pwax-head') belongs to the document, not the page.
        expect(tag('meta[name="verification"]').getAttribute('content')).toBe('mine');
        expect(tag('meta[property="og:title"]')).toBeNull();
    });

    it('skips an incomplete tag rather than emitting a broken one', () => {
        applyHead({
            meta: [
                { attribute: 'property', key: 'og:image', content: '' },
                { attribute: 'property', key: '', content: 'orphan' },
                { attribute: 'property', key: 'og:title', content: 'Kept' },
            ],
        });

        expect(document.head.querySelectorAll('meta[data-pwax-head]')).toHaveLength(1);
        expect(tag('meta[property="og:title"]').getAttribute('content')).toBe('Kept');
    });
    /**
     * The server now sends a head for every page, including one that declares nothing.
     * These are the two halves of why that is safe — and the reason the omission it
     * replaced was not.
     */
    it('keeps the application-wide description when a page names none', () => {
        document.head.innerHTML = '<meta name="description" content="The Acme application.">';

        applyHead({ title: 'Acme' });

        // The omission this replaced was justified on the belief that an empty head would
        // wipe this. It does not: an empty description is left alone, deliberately, because
        // the shell emits one for the application as a whole.
        expect(tag('meta[name="description"]').getAttribute('content')).toBe(
            'The Acme application.'
        );
    });

    it("drops the previous page's canonical when the next page has none", () => {
        document.head.innerHTML = '<link rel="canonical" href="https://example.test/post">';

        applyHead({ title: 'Acme' });

        // A canonical URL that outlives its page points every crawler that follows a
        // client-side navigation at the wrong document.
        expect(tag('link[rel="canonical"]')).toBeNull();
    });
});
