/**
 * The routed page component's template.
 *
 * Shared by the client runtime (`src/js/page.js`) and the Node SSR bridge
 * (`bin/ssr.mjs`), and that sharing is the point of the file rather than a convenience.
 *
 * `PwaxPage` has two root-level `<template>` blocks, so it is a *fragment* component:
 * Vue brackets its output with `<!--[-->` / `<!--]-->` anchors and emits a `<!---->`
 * placeholder for each branch that did not render. Hydration compares that structure node
 * for node. The bridge used to render the page component directly under `<router-view>`,
 * which produced the right elements with none of the anchors — so every prerendered page
 * failed to hydrate, Vue discarded the server's DOM, and the runtime drew the page again
 * from scratch. Building both sides from this one function is what makes that class of
 * drift impossible.
 *
 * `.mjs`, deliberately: `package.json` is `export-ignore`d from the Composer package, so a
 * `.js` file here would be resolved against the *host application's* `package.json` and
 * loaded as CommonJS, and `bin/ssr.mjs` could not import it. The extension settles it.
 */

/**
 * The loader, used when the server did not send one.
 */
export const DEFAULT_LOADER = '<div class="pwax-loading" role="status">Loading…</div>';

/**
 * The error screen, used when the server did not send one.
 *
 * A trimmed copy of `components/error.blade.php` — no home link, because this file cannot
 * know where home is. Keep the two in step: an application that publishes the view should
 * not get a visibly different screen from one that does not.
 */
export const DEFAULT_ERROR = `
    <div class="pwax-screen pwax-error" role="alert">
        <div class="pwax-screen__panel">
            <p class="pwax-screen__code" v-text="error.status"></p>
            <h1 class="pwax-screen__title" v-text="error.statusText"></h1>
            <p class="pwax-screen__message" v-text="error.message"></p>
            <div class="pwax-screen__actions">
                <button type="button" class="pwax-button pwax-retry" @click="retry">Try again</button>
            </div>
        </div>
    </div>
`;

/**
 * Build the page component's template from the server's markup fragments.
 *
 * Loader and error markup come from the server so they stay customisable through Blade
 * while this bundle itself remains static and cacheable.
 *
 * @param {{loader?: string, error?: string}} templates
 * @returns {string}
 */
export function pageTemplate(templates = {}) {
    return `
            <template v-if="error">${templates.error || DEFAULT_ERROR}</template>
            <template v-else>
                <template v-if="!component">${templates.loader || DEFAULT_LOADER}</template>
                <component v-if="component" :is="component" :key="renderedPath"></component>
            </template>
        `;
}
