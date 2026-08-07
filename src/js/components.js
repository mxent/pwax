/**
 * Resolving components referenced by the `@pwax` Blade directive.
 *
 * `component()` returns a Vue async component *synchronously*. That is deliberate and
 * it is what makes circular imports work.
 *
 * In 1.x the directive expanded to `await window.pwaxImport(...)`, so two components
 * that referenced each other each waited at module top level for the other to finish —
 * a deadlock in native ES modules. The workaround was to publish a mutable placeholder
 * object into a cache before compiling and then `Object.assign` the real component onto
 * it afterwards, so a cycle would find *something* to hold. That left a window in which
 * a component could be rendered with only a template and no methods.
 *
 * Deferring resolution to render time removes the cycle entirely: neither module has to
 * wait for the other to evaluate, and Vue resolves each one the first time it is
 * actually rendered.
 */

import { importModule, styleMetadata, toComponentOptions } from './modules.js';

/**
 * @param {{styles: ReturnType<import('./styles.js').createStyleManager>, nonce?: string|null}} deps
 */
export function createComponentLoader({ styles, nonce = null }) {
    /**
     * Load a component's options, applying its styles and external assets.
     *
     * @param {string} url
     * @param {string} exportName
     */
    async function load(url, exportName = '') {
        const module = await importModule(url);
        const meta = styleMetadata(module);

        styles.acquire(url, meta.style, { nonce });

        // Loaded after the module evaluates rather than before, because a component's
        // external assets are only discoverable from the module itself. Code that runs
        // at module top level therefore cannot rely on them; code in `setup`, `mounted`
        // or a method — which is where a charting or map library is actually used — can.
        await Promise.all([
            ...meta.styles.map((href) => styles.link(href)),
            ...meta.scripts.map((src) => styles.script(src)),
        ]);

        return toComponentOptions(module, exportName);
    }

    /**
     * A Vue async component for a Pwax component URL.
     *
     * @param {string} url
     * @param {string} exportName
     */
    function component(url, exportName = '') {
        if (typeof Vue === 'undefined' || !Vue.defineAsyncComponent) {
            throw new Error(
                'pwax: Vue is not loaded. Check that the Vue script tag comes before pwax.js.'
            );
        }

        return Vue.defineAsyncComponent(() => load(url, exportName));
    }

    return { load, component };
}
