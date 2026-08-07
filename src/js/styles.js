/**
 * Reference-counted injection of component styles.
 *
 * This replaces the strategy 1.x used, which was to mark every injected tag with a
 * `pwax-attached` attribute and remove all of them on each navigation. That also removed
 * the styles of imported components that were still mounted, so those components lost
 * their styling as soon as the user navigated. The workaround at the time was to
 * re-inject the styles of every cached component on every cache hit, which grew linearly
 * with the number of components the session had ever touched.
 *
 * Counting references fixes the cause: a stylesheet is inserted when its first user
 * appears and removed when its last user goes away, and nothing else is ever touched.
 */

export function createStyleManager(doc = document) {
    /** @type {Map<string, {count: number, el: Element}>} */
    const entries = new Map();

    function head() {
        return doc.head || doc.getElementsByTagName('head')[0];
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
