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

    /** @type {Map<string, object>} */
    const components = new Map();

    /**
     * A Vue async component for a Pwax component URL.
     *
     * Memoised on url + export name. Vue treats each `defineAsyncComponent` result as a
     * distinct component type, so minting a new one per call would remount the subtree
     * whenever a parent re-rendered and would give `<KeepAlive>` a different identity to
     * cache each time. Returning the same object keeps that stable.
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

        const key = `${url}|${exportName}`;
        const cached = components.get(key);

        if (cached) {
            return cached;
        }

        const async = Vue.defineAsyncComponent(() => load(url, exportName));

        // Make the commonest misuse explain itself.
        //
        // `components: { Button: () => @pwax('button') }` is the Vue 2 idiom, dropped in
        // Vue 3. Vue sees a function, treats the entry as a *functional component*, calls
        // it during render, and gets this object back instead of vnodes — at which point
        // it falls back to `String(child)`. The default `Object.prototype.toString` makes
        // that `[object Object]` on screen, with no warning anywhere.
        //
        // Overriding toString puts the fix itself where the developer is already looking.
        async.toString = () =>
            'pwax: a component was rendered as text. This usually means it was wrapped in ' +
            `an arrow function — write \`@pwax('…')\` rather than \`() => @pwax('…')\`. ` +
            'Vue 3 does not accept `() => Component` in the `components` option.';

        components.set(key, async);

        return async;
    }

    return { load, component };
}
