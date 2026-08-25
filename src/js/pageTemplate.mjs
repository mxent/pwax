/**
 * The routed page component's template.
 *
 * Its own module rather than a string inside `page.js` so that the loader and error
 * markup — the two fragments an application can replace through Blade — have one place
 * that decides how they are assembled, and so the defaults below can be asserted against
 * directly in a test.
 *
 * `PwaxPage` has several root-level branches, so it is a *fragment* component: Vue
 * brackets its output with `<!--[-->` / `<!--]-->` anchors and emits a `<!---->`
 * placeholder for each branch that did not render. That is deliberate — the loader, the
 * error screen and the page share one slot and must not be able to render two at once.
 *
 * The branches are flat rather than nested in a `v-else` because `<KeepAlive>` has to stay
 * mounted for its cache to survive. Nested, a navigation that failed would render the
 * error screen *in place of* the whole else-branch, taking `<KeepAlive>` down with it and
 * discarding every retained page — so recovering from one failed navigation would cost the
 * state of all the others. Flat, the error screen renders alongside a `<KeepAlive>` whose
 * slot is empty, which deactivates the current page without destroying anything.
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
 * `retain` is how many page instances `<KeepAlive>` may hold, or `0` for no `<KeepAlive>`
 * at all. A retained page keeps everything a component instance owns — a half-filled
 * form, a scrolled list, an open panel — so going back returns to the page rather than to
 * a fresh copy of it.
 *
 * The opted-out page renders *outside* `<KeepAlive>` rather than being excluded from it.
 * Vue's `exclude` matches on a component's `name`, which a page compiled from a Blade view
 * need not have; a second slot guarded on the same flag needs no name and cannot match the
 * wrong page. Both are guarded so exactly one can render.
 *
 * @param {{loader?: string, error?: string}} templates
 * @param {number} retain
 * @returns {string}
 */
export function pageTemplate(templates = {}, retain = 0) {
    const page = (guard) =>
        `<component v-if="${guard}" :is="component" :key="renderedPath"></component>`;

    const slot =
        retain > 0
            ? `<KeepAlive :max="${retain}">${page('component && !error && keepState')}</KeepAlive>` +
              `\n                ${page('component && !error && !keepState')}`
            : page('component && !error');

    return `
            <template v-if="error">${templates.error || DEFAULT_ERROR}</template>
            <template v-else-if="!component">${templates.loader || DEFAULT_LOADER}</template>
            ${slot}
        `;
}
