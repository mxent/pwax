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
 * The list is exactly what `@json-render/vue` imports, plus the four helpers
 * `src/js/json/index.js` needs for the catalog wrappers. `assertVueExports()` in
 * `build.js` fails the build if the package ever reaches for one that is not here —
 * without it, an upgrade would produce a bundle whose missing import is `undefined`
 * and whose failure is a `TypeError` at render time, far from its cause.
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
export const capitalize = Vue.capitalize;
export const Comment = Vue.Comment;
export const computed = Vue.computed;
export const defineComponent = Vue.defineComponent;
export const h = Vue.h;
export const inject = Vue.inject;
export const isRef = Vue.isRef;
export const markRaw = Vue.markRaw;
export const onBeforeUnmount = Vue.onBeforeUnmount;
export const onErrorCaptured = Vue.onErrorCaptured;
export const onMounted = Vue.onMounted;
export const onUnmounted = Vue.onUnmounted;
export const provide = Vue.provide;
export const ref = Vue.ref;
export const shallowRef = Vue.shallowRef;
export const toHandlerKey = Vue.toHandlerKey;
export const watch = Vue.watch;
