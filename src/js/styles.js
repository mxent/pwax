/**
 * Reference-counted injection of component styles.
 *
 * The obvious alternative — mark every injected tag and sweep them all on each navigation
 * — also removes the styles of imported components that are still mounted, so those
 * components lose their styling the moment the visitor navigates. Re-injecting the styles
 * of every cached component on every cache hit papers over that, and grows linearly with
 * the number of components the session has ever touched.
 *
 * Counting references addresses the cause: a stylesheet is inserted when its first user
 * appears and removed when its last user releases it, and nothing else is ever touched.
 *
 * "Releases it" rather than "goes away", because the two are not the same for every key.
 * A page's own stylesheet is released on the navigation away from it — `page.js` owns that
 * key and gives it back. A component loaded through `@pwaxImport` is not: its module is
 * cached for the session, so `load()` runs once and acquires once, and nothing releases it.
 * See the note in `components.js` for why that is the choice rather than an oversight.
 */

export function createStyleManager(doc = document) {
    /** @type {Map<string, {count: number, el: Element}>} */
    const entries = new Map();

    function head() {
        return doc.head || doc.getElementsByTagName('head')[0];
    }

    /**
     * An already-present `<style>` for this key.
     *
     * Scanned rather than matched with an attribute selector: keys are component
     * identifiers and `pwax:page`, and a colon in a selector value would have to be quoted
     * and escaped. There are only ever a handful of these elements.
     *
     * @param {string} key
     * @returns {Element|null}
     */
    function findStyle(key) {
        for (const el of doc.querySelectorAll('style[data-pwax-style]')) {
            if (el.getAttribute('data-pwax-style') === key) {
                return el;
            }
        }

        return null;
    }

    /**
     * Register a user of a stylesheet, inserting it if this is the first.
     *
     * @param {string} key stable identity for the style — the component id
     * @param {string} css
     * @param {{nonce?: string|null}} options
     */
    function acquire(key, css, options = {}) {
        if (!css) {
            return;
        }

        const existing = entries.get(key);

        if (existing) {
            existing.count += 1;
            return;
        }

        // A `<style>` for this key can be in the document with no entry behind it: a reboot
        // (`window.pwax.start()`) builds a fresh style manager over the document the old one
        // left, and the stylesheets of components loaded through `@pwaxImport` are held for
        // the life of the session rather than released. Adopting the element rather than
        // appending a second copy is what keeps the count honest — and what lets `release()`
        // actually remove it, instead of leaving one boot's rules applying to the next.
        const adopted = findStyle(key);

        if (adopted) {
            entries.set(key, { count: 1, el: adopted });
            return;
        }

        const el = doc.createElement('style');
        el.textContent = css;
        el.setAttribute('data-pwax-style', key);

        if (options.nonce) {
            el.setAttribute('nonce', options.nonce);
        }

        head().appendChild(el);
        entries.set(key, { count: 1, el });
    }

    /**
     * Drop a user of a stylesheet, removing it when the last one goes.
     *
     * @param {string} key
     */
    function release(key) {
        const entry = entries.get(key);

        if (!entry) {
            return;
        }

        entry.count -= 1;

        if (entry.count > 0) {
            return;
        }

        entry.el.remove();
        entries.delete(key);
    }

    /**
     * Load an external stylesheet once, resolving when it has applied.
     *
     * Resolving on `load` matters: returning before the sheet applies produces a flash
     * of unstyled content on every navigation.
     *
     * @param {string} href
     * @returns {Promise<void>}
     */
    function link(href) {
        const absolute = new URL(href, doc.baseURI).href;
        const already = Array.from(doc.querySelectorAll('link[rel="stylesheet"]')).some(
            (el) => el.href === absolute
        );

        if (already) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const el = doc.createElement('link');
            el.rel = 'stylesheet';
            el.href = href;
            el.setAttribute('data-pwax-link', '');
            el.addEventListener('load', () => resolve());
            el.addEventListener('error', () =>
                reject(new Error(`pwax: failed to load stylesheet ${href}`))
            );
            head().appendChild(el);
        });
    }

    /**
     * Load an external script once, resolving when it has executed.
     *
     * @param {string} src
     * @returns {Promise<void>}
     */
    function script(src) {
        const absolute = new URL(src, doc.baseURI).href;
        const already = Array.from(doc.querySelectorAll('script[src]')).some(
            (el) => el.src === absolute
        );

        if (already) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const el = doc.createElement('script');
            el.src = src;
            el.setAttribute('data-pwax-script', '');
            el.addEventListener('load', () => resolve());
            el.addEventListener('error', () =>
                reject(new Error(`pwax: failed to load script ${src}`))
            );
            head().appendChild(el);
        });
    }

    /** Number of distinct stylesheets currently mounted. Used by tests. */
    function size() {
        return entries.size;
    }

    /** Current reference count for a key. Used by tests. */
    function count(key) {
        return entries.get(key)?.count ?? 0;
    }

    return { acquire, release, link, script, size, count };
}
