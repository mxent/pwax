/**
 * Resolving components referenced by the `@pwax` Blade directive.
 *
 * `component()` returns a Vue async component *synchronously*. That is deliberate and
 * it is what makes circular imports work.
 *
 * Expanding the directive to `await window.pwaxImport(...)` instead would make two
 * components that reference each other each wait at module top level for the other to
 * finish — a deadlock in native ES modules, and one whose usual workaround (publishing a
 * mutable placeholder into a cache and `Object.assign`ing the real component onto it
 * later) leaves a window in which a component renders with a template and no methods.
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

        // Acquired and never released, which is deliberate and is the one place the style
        // manager's reference counting does not balance.
        //
        // `importModule` caches the module and `component()` memoises the async component,
        // so this function runs at most once per URL per session: there is no second
        // acquire to pair a release with. Releasing on unmount instead would mean
        // re-inserting the stylesheet on every remount — a `v-if` toggling an imported
        // component would flash it unstyled each time it came back, and that is a far more
        // visible failure than a `<style>` element outliving its last user.
        //
        // The residue is bounded by the number of distinct components a session imports,
        // and each one's rules are scoped to that component unless its author deliberately
        // wrote an unscoped `<style>`.
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
        // `components: { Button: () => @pwaxImport('button') }` is the Vue 2 idiom, dropped in
        // Vue 3. Vue sees a function, treats the entry as a *functional component*, calls
        // it during render, and gets this object back instead of vnodes — at which point
        // it falls back to `String(child)`. The default `Object.prototype.toString` makes
        // that `[object Object]` on screen, with no warning anywhere.
        //
        // Overriding toString puts the fix itself where the developer is already looking.
        async.toString = () =>
            'pwax: a component was rendered as text. This usually means it was wrapped in ' +
            `an arrow function — write \`@pwaxImport('…')\` rather than \`() => @pwaxImport('…')\`. ` +
            'Vue 3 does not accept `() => Component` in the `components` option.';

        components.set(key, async);

        return async;
    }

    return { load, component };
}
