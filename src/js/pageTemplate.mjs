/**
 * The routed page component's template.
 *
 * Its own module rather than a string inside `page.js` so that the loader and error
 * markup — the two fragments an application can replace through Blade — have one place
 * that decides how they are assembled, and so the defaults below can be asserted against
 * directly in a test.
 *
 * `PwaxPage` has two root-level `<template>` blocks, so it is a *fragment* component: Vue
 * brackets its output with `<!--[-->` / `<!--]-->` anchors and emits a `<!---->`
 * placeholder for each branch that did not render. That is deliberate — the loader, the
 * error screen and the page share one slot and must not be able to render two at once.
 *
 * `.mjs`, deliberately: `package.json` is `export-ignore`d from the Composer package, so a
 * `.js` file here would be resolved against the *host application's* `package.json` and
 * loaded as CommonJS by any Node tool that reaches it. The extension settles it.
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
