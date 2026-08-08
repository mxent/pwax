/**
 * Loading and caching of component modules.
 *
 * Components are imported from real, same-origin URLs (`/__pwax__/c/{id}.js`) rather
 * than from `blob:` or `data:` URLs built out of a script string. Three things follow
 * from that, all of which 1.x gave up:
 *
 *   1. A Content-Security-Policy of `script-src 'self'` is enough. Blob and data URLs
 *      each require their own scheme in the policy, and `data:` in `script-src` is
 *      widely considered unsafe because it makes any injected string executable.
 *   2. The browser's HTTP cache applies, so a revisited component costs a 304 at most.
 *   3. Nothing leaks. A blob URL creates a module record keyed on a URL that is unique
 *      per call, and module records are never collected — so 1.x accumulated one dead
 *      module per navigation for the lifetime of the page.
 *
 * The in-memory map below deduplicates concurrent and repeat imports of the same URL,
 * storing the promise rather than the result so that two callers racing for the same
 * component share one request.
 */

/** @type {Map<string, Promise<any>>} */
const inflight = new Map();

/** Indirection so tests can supply modules without a network or a real module graph. */
let importer = (url) => import(/* @vite-ignore */ url);

/**
 * Replace the underlying dynamic import. Test seam only.
 *
 * @param {(url: string) => Promise<any>} fn
 */
export function setImporter(fn) {
    importer = fn;
}

/**
 * Import a component module, at most once per URL.
 *
 * @param {string} url
 * @returns {Promise<any>}
 */
export function importModule(url) {
    const cached = inflight.get(url);

    if (cached) {
        return cached;
    }

    // A failed import must not be cached, or a transient network error would poison the
    // component for the rest of the session.
    const promise = importer(url).catch((error) => {
        inflight.delete(url);
        throw error;
    });

    inflight.set(url, promise);

    return promise;
}

/**
 * Turn a loaded module into Vue component options.
 *
 * The module exposes the author's own exports plus the template, style and scope that
 * the server attached. A named export can be selected with `exportName`, which is how
 * `@pwaxImport('Modal from components.modal')` reaches a specific export.
 *
 * @param {any} module
 * @param {string} exportName
 */
export function toComponentOptions(module, exportName = '') {
    if (exportName && exportName.length) {
        const named = module[exportName];

        if (!named) {
            throw new Error(`pwax: module has no export named "${exportName}"`);
        }

        return named;
    }

    const options = { ...(module.default || {}) };

    // An author who wrote their own `template` wins; otherwise use the Blade one.
    if (!options.template && module.__pwaxTemplate) {
        options.template = module.__pwaxTemplate;
    }

    return options;
}

/**
 * The style metadata a module carries alongside its component options.
 *
 * @param {any} module
 */
export function styleMetadata(module) {
    return {
        style: module.__pwaxStyle || '',
        scope: module.__pwaxScope || null,
        styles: module.__pwaxStyles || [],
        scripts: module.__pwaxScripts || [],
    };
}

/**
 * Compile a component's inline script into a module, at most once per content hash.
 *
 * Page components carry their script inline rather than at a URL, because a page is
 * rendered with controller data and cannot be re-derived from its view name alone —
 * fetching `/__pwax__/c/{id}.js` for it would render the view with no data at all.
 *
 * Keying on the server-supplied content hash is what stops this leaking. A blob URL is
 * unique per call, and ES module records are keyed by URL and never garbage collected,
 * so compiling on every navigation — as 1.x did — left one dead module behind each time.
 * With the hash as the key, returning to a page reuses the module already compiled.
 *
 * @param {string} source
 * @param {string} hash
 * @returns {Promise<any>}
 */
export function importInlineModule(source, hash) {
    const key = `inline:${hash}`;
    const cached = inflight.get(key);

    if (cached) {
        return cached;
    }

    const url = URL.createObjectURL(new Blob([source], { type: 'text/javascript' }));

    const promise = importer(url)
        .then((module) => {
            // The module record survives on its own; the blob backing it does not need to.
            URL.revokeObjectURL(url);
            return module;
        })
        .catch((error) => {
            URL.revokeObjectURL(url);
            inflight.delete(key);
            throw error;
        });

    inflight.set(key, promise);

    return promise;
}

/** Test seam: forget every cached module. */
export function resetModuleCache() {
    inflight.clear();
}

/** Test seam: how many distinct modules have been imported. */
export function moduleCacheSize() {
    return inflight.size;
}
