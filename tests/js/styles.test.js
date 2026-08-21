import { beforeEach, describe, expect, it } from 'vitest';
import { createStyleManager } from '../../src/js/styles.js';

describe('style manager', () => {
    let styles;

    beforeEach(() => {
        document.head.innerHTML = '';
        styles = createStyleManager(document);
    });

    it('inserts a stylesheet on first acquire', () => {
        styles.acquire('a', '.a { color: red }');

        const el = document.querySelector('style[data-pwax-style="a"]');
        expect(el).not.toBeNull();
        expect(el.textContent).toBe('.a { color: red }');
        expect(styles.size()).toBe(1);
    });

    it('does not insert twice for the same key', () => {
        styles.acquire('a', '.a{}');
        styles.acquire('a', '.a{}');

        expect(document.querySelectorAll('style[data-pwax-style="a"]')).toHaveLength(1);
        expect(styles.count('a')).toBe(2);
    });

    it('keeps the stylesheet while other users remain', () => {
        styles.acquire('a', '.a{}');
        styles.acquire('a', '.a{}');
        styles.release('a');

        expect(document.querySelector('style[data-pwax-style="a"]')).not.toBeNull();
        expect(styles.count('a')).toBe(1);
    });

    it('removes the stylesheet when the last user goes', () => {
        styles.acquire('a', '.a{}');
        styles.release('a');

        expect(document.querySelector('style[data-pwax-style="a"]')).toBeNull();
        expect(styles.size()).toBe(0);
    });

    it('releasing an unknown key is a no-op', () => {
        expect(() => styles.release('nope')).not.toThrow();
        expect(styles.size()).toBe(0);
    });

    it('never goes below zero references', () => {
        styles.acquire('a', '.a{}');
        styles.release('a');
        styles.release('a');

        expect(styles.count('a')).toBe(0);
        expect(styles.size()).toBe(0);
    });

    it('ignores empty css', () => {
        styles.acquire('a', '');

        expect(styles.size()).toBe(0);
    });

    it('applies a nonce when given one', () => {
        styles.acquire('a', '.a{}', { nonce: 'abc123' });

        expect(document.querySelector('style[data-pwax-style="a"]').getAttribute('nonce')).toBe(
            'abc123'
        );
    });

    // The 1.x bug this whole module exists to fix: navigating away removed every
    // injected style, including those of imported components still on screen.
    it('a page navigation does not disturb an imported component stylesheet', () => {
        styles.acquire('/c/modal.js', '.modal{}');
        styles.acquire('pwax:page:home', '.home{}');

        styles.release('pwax:page:home');
        styles.acquire('pwax:page:about', '.about{}');

        expect(document.querySelector('style[data-pwax-style="/c/modal.js"]')).not.toBeNull();
        expect(document.querySelector('style[data-pwax-style="pwax:page:about"]').textContent).toBe(
            '.about{}'
        );
    });

    // `page.js` acquires the incoming stylesheet *before* releasing the outgoing one, on
    // purpose, so that the swap never leaves a frame with neither applied. Under one shared
    // key that overlap was the bug rather than the feature: acquire found an existing entry,
    // bumped its count and returned, so the element kept the old page's CSS and the new
    // page's was never inserted. Distinct keys are what make the overlap safe, and this
    // asserts the order `page.js` actually uses — the test above uses the other one.
    it('swaps the page stylesheet when the incoming one is acquired before the outgoing is released', () => {
        styles.acquire('pwax:page:home', '.home{}');
        styles.acquire('pwax:page:about', '.about{}');
        styles.release('pwax:page:home');

        expect(document.querySelector('style[data-pwax-style="pwax:page:home"]')).toBeNull();
        expect(document.querySelector('style[data-pwax-style="pwax:page:about"]').textContent).toBe(
            '.about{}'
        );
    });

    // The server inlines a prerendered page's stylesheet into the head. Adopting it is what
    // keeps one element per page rather than two with the same rules.
    it('adopts a stylesheet the server already put in the document', () => {
        const inlined = document.createElement('style');
        inlined.setAttribute('data-pwax-style', 'pwax:page:home');
        inlined.textContent = '.home{}';
        document.head.appendChild(inlined);

        styles.acquire('pwax:page:home', '.home{}');

        expect(document.querySelectorAll('style[data-pwax-style="pwax:page:home"]')).toHaveLength(
            1
        );

        styles.release('pwax:page:home');

        expect(document.querySelector('style[data-pwax-style="pwax:page:home"]')).toBeNull();
    });

    it('does not re-add a stylesheet link that is already present', async () => {
        const existing = document.createElement('link');
        existing.rel = 'stylesheet';
        existing.href = '/a.css';
        document.head.appendChild(existing);

        await styles.link('/a.css');

        expect(document.querySelectorAll('link[rel="stylesheet"]')).toHaveLength(1);
    });

    it('resolves link() only once the sheet has loaded', async () => {
        let resolved = false;
        const promise = styles.link('/b.css').then(() => {
            resolved = true;
        });

        const el = document.querySelector('link[data-pwax-link]');
        expect(el).not.toBeNull();
        expect(resolved).toBe(false);

        el.dispatchEvent(new Event('load'));
        await promise;

        expect(resolved).toBe(true);
    });

    it('rejects link() when the sheet fails', async () => {
        const promise = styles.link('/missing.css');
        document.querySelector('link[data-pwax-link]').dispatchEvent(new Event('error'));

        await expect(promise).rejects.toThrow(/failed to load stylesheet/);
    });

    it('does not re-add a script that is already present', async () => {
        const existing = document.createElement('script');
        existing.src = '/a.js';
        document.head.appendChild(existing);

        await styles.script('/a.js');

        expect(document.querySelectorAll('script[src]')).toHaveLength(1);
    });
});
