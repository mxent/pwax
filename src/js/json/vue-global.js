/**
 * The `vue` module, resolved against the global build Pwax already serves.
 *
 * `@json-render/vue` is published with `vue` as a peer dependency and imports it as a
 * bare specifier. Pwax does not ship Vue as a module — it serves `vue.global.prod.js`
 * as a plain script tag and everything reads `window.Vue` — so bundling the package
 * would either inline a second copy of Vue (two runtimes, two reactivity systems, and
 * a component from one that cannot be mounted by the other) or leave an unresolvable
 * import in an IIFE that has no module loader behind it.
 *
 * `build.js` aliases `vue` to this file instead. Every name below is re-exported from
 * the global, so the bundled package gets the same Vue the rest of the application is
 * using, and the bundle stays free of Vue itself.
 *
 * The list is exactly the union of two sets: what `@json-render/vue` and
 * `@json-render/core` import from `vue`, and the five names
 * `src/js/json/index.js` needs of its own for the catalog wrappers and the
 * confirmation dialog — `Comment`, `camelize`, `markRaw`, `nextTick` and
 * `toHandlerKey`.
 *
 * A name the packages need and this file lacks fails the build: esbuild reports
 * `No matching export in "src/js/json/vue-global.js" for import "…"`, naming the
 * file to edit and the name to add, which is the tripwire for a json-render upgrade
 * reaching for a Vue API the shim has never had to cover. See the note above
 * `vueAlias()` in `build.js`, which is where the decision not to check this
 * separately is recorded.
 *
 * Nothing catches the other direction — an export nothing imports is silently
 * dead — so keep the list tight by hand when trimming an import.
 *
 * Read once, at module scope: the bundle is loaded on demand, long after the Vue
 * script tag has evaluated, so there is no ordering hazard to defend against.
 */

const Vue = globalThis.Vue;

if (!Vue) {
    throw new Error(
        'pwax: the JSON runtime was loaded before Vue. It reads the global Vue build, ' +
            'which the shell emits before pwax.js — so this should be unreachable unless ' +
            'the bundle was added to the page by hand.'
    );
}

export const camelize = Vue.camelize;
export const Comment = Vue.Comment;
export const computed = Vue.computed;
export const defineComponent = Vue.defineComponent;
export const h = Vue.h;
export const inject = Vue.inject;
export const isRef = Vue.isRef;
export const markRaw = Vue.markRaw;
export const nextTick = Vue.nextTick;
export const onBeforeUnmount = Vue.onBeforeUnmount;
export const onErrorCaptured = Vue.onErrorCaptured;
export const onMounted = Vue.onMounted;
export const onUnmounted = Vue.onUnmounted;
export const provide = Vue.provide;
export const ref = Vue.ref;
export const shallowRef = Vue.shallowRef;
export const toHandlerKey = Vue.toHandlerKey;
export const watch = Vue.watch;
