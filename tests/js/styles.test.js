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
        styles.acquire('pwax:page', '.home{}');

        styles.release('pwax:page');
        styles.acquire('pwax:page', '.about{}');

        expect(document.querySelector('style[data-pwax-style="/c/modal.js"]')).not.toBeNull();
        expect(document.querySelector('style[data-pwax-style="pwax:page"]').textContent).toBe(
            '.about{}'
        );
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
