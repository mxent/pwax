/**
 * Pwax client runtime entry point.
 *
 * Boot order matters and is deliberately flat: read config, build the pieces, mount.
 * Everything the server needs to say is in two JSON islands, so this file never has to
 * be generated or interpolated.
 */

import { resetModuleCache } from './modules.js';
import { createComponentLoader } from './components.js';
import { loadConfig, loadInitialPayload, loadSsrState } from './config.js';
import { resolveExtensions } from './extensions.js';
import { createHttp } from './http.js';
import { createBadgeApi, createInstallApi, createStorageApi, watchInstall } from './install.js';
import { createLaunchApi, createShareApi, watchLaunch } from './launch.js';
import { importModule } from './modules.js';
import { createPushApi } from './push.js';
import { createPageComponent, resolveInitialPage } from './page.js';
import { createPrefetcher } from './prefetch.js';
import { createProgress } from './progress.js';
import { createRouter } from './router.js';
import { createServiceWorkerApi, registerServiceWorker } from './serviceWorker.js';
import { createStyleManager } from './styles.js';
import { createSyncApi } from './sync.js';

const DEFAULT_CONTENT = '<main><router-view></router-view></main>';

/**
 * Drop whitespace-only text nodes from the edges of a prerendered mount element.
 *
 * Vue hydrates from `container.firstChild`, and a mismatch there is not a small one: it
 * discards the text node, renders the application from scratch *before* the next sibling,
 * and leaves the server's markup in place — so the visitor sees the whole page twice.
 * Indented markup inside `<div id="pwax">` is enough to cause it, which is exactly what a
 * Blade view looks like when someone formats it.
 *
 * The shipped shell emits the prerendered markup as this element's only child, so normally
 * there is nothing here to do. This is for the shell an application published with
 * `vendor:publish --tag=pwax-views` and has since reformatted, and for one published before
 * SSR existed. Silent, catastrophic and entirely avoidable is a bad combination to leave to
 * whoever edits that file next.
 *
 * Only whitespace text is removed. A leading comment is left alone: an application that
 * overrides `pwax.blade.content` with a multi-root template makes the application's own
 * root a fragment, and `<!--[-->` is then a node hydration genuinely expects.
 */
function trimHydrationWhitespace(el) {
    const blank = (node) => node && node.nodeType === 3 && node.data.trim() === '';

    while (blank(el.firstChild)) {
        el.removeChild(el.firstChild);
    }

    while (blank(el.lastChild)) {
        el.removeChild(el.lastChild);
    }
}

async function boot() {
    const config = loadConfig();
    const initial = loadInitialPayload();

    // Before anything is awaited. `beforeinstallprompt` fires early, once, and is never
    // replayed — a listener added after it has fired never sees it, and with it goes the
    // application's only chance to offer installation on this page load.
    watchInstall();

    // Same reason, different API. A launch — a file opened, a `web+thing:` link followed, a
    // page shared to the app — happens before the document has finished loading, and the
    // queue holds it only until a consumer is set. Set late, the files are gone.
    watchLaunch();

    const http = createHttp(config);
    const styles = createStyleManager(document);
    const prefetcher = createPrefetcher(
        http,
        config.prefetch === false ? { mode: false } : config.prefetch || {}
    );
    const loader = createComponentLoader({ styles, nonce: config.nonce });

    // Null when the application turned it off, and every call site uses `?.` — a disabled
    // progress bar should cost nothing at all, not an object that does nothing.
    //
    // It covers navigations only. This load is the browser's own wait, and the shell's
    // spinner already says so.
    const progressBar = config.progress === false ? null : createProgress(config.progress || {});

    // Published before anything is mounted, so component scripts can call it during
    // their own evaluation.
    window.pwax = {
        version: __PWAX_VERSION__,
        config,
        http,
        styles,
        component: loader.component,
        load: loader.load,
        import: importModule,
        sw: createServiceWorkerApi(),
        install: createInstallApi(),
        badge: createBadgeApi(),
        storage: createStorageApi(),
        push: createPushApi(config.push || {}, http),
        sync: createSyncApi(config, http),
        launch: createLaunchApi(),
        share: createShareApi().share,
        prefetch: prefetcher.prefetch,
        // Exposed so an application can wrap its own long-running work — a form
        // submission, a report — in the same indicator its navigations use.
        progress: progressBar,
        // The server's prerendered state, or null when this page was not prerendered.
        // A component reads it in `data()`/`setup()` to seed its initial state so the
        // hydrated reactive values match the server-rendered DOM. Published before mount
        // so a page component built during `createPageComponent` can already see it.
        ssrState: null,
    };

    if (typeof Vue === 'undefined') {
        throw new Error(
            'pwax: Vue is not loaded. The Vue script tag must come before pwax.js. Use the full ' +
                'build (vue.global.prod.js) unless you run `php artisan pwax:compile`, which is ' +
                'what makes the runtime-only build safe to serve.'
        );
    }

    // Started together, awaited apart.
    //
    // Plugins and directives have to be registered before `mount()` — Vue offers no way to
    // add either to a running application — so first paint genuinely waits for them.
    // Middleware does not: it is read inside `runMiddleware()`, after a page's options are
    // in hand. Awaiting it here made a configured module middleware delay the first paint
    // of a page whose component was already inlined in the document and needed no network
    // at all, which is the one thing this architecture exists to avoid.
    const pluginsReady = resolveExtensions(config.plugins, loader);
    const directivesReady = resolveExtensions(config.directives, loader);
    const middlewareReady = resolveExtensions(config.middleware, loader);

    const [plugins, directives] = await Promise.all([pluginsReady, directivesReady]);

    // The mount element carries `data-pwax-prerendered` when the server embedded
    // prerendered HTML for this page. That, plus the initial payload's `hydrate` flag, is
    // the signal to hydrate the existing DOM rather than mount from scratch — Vue's
    // `createSSRApp` walks the existing nodes and attaches reactivity to them instead of
    // replacing them. A page that was not prerendered, or a shell published before this
    // feature existed, falls through to `createApp` unchanged.
    const mount = document.getElementById(config.mount || 'pwax');

    if (!mount) {
        throw new Error(`pwax: mount element #${config.mount || 'pwax'} was not found.`);
    }

    const prerendered =
        !!initial && initial.hydrate === true && mount.hasAttribute('data-pwax-prerendered');

    if (prerendered) {
        trimHydrationWhitespace(mount);
    }

    // A settle-mode prerender is not hydrated: the DOM it produced carries work the
    // synchronous virtual DOM does not, so the client builds the page again. Anything the
    // server's copy left in `<body>` outside the mount — a toast container, a modal portal,
    // a cookie banner — has to go before that happens, or the application's own copy lands
    // beside the server's and the visitor sees each of them twice.
    //
    // Removed on every boot rather than only in settle mode: the marker is only ever
    // emitted by a settle prerender, so finding one is itself the signal.
    for (const node of document.querySelectorAll('[data-pwax-settle-body]')) {
        node.remove();
    }

    // Published before the page component is built, so a component script evaluated during
    // `resolveInitialPage` can already read it.
    window.pwax.ssrState = prerendered ? loadSsrState(config.stateIslandId) : null;

    // Resolved *before* the application is created, not in a lifecycle hook. Vue builds the
    // virtual DOM it compares against the server's markup on the first render, and no hook
    // can hold that render back for a promise — so the page component has to be in hand by
    // the time `mount()` is called, or hydration mismatches and the prerender is discarded.
    let initialComponent = null;

    if (prerendered && initial.component) {
        try {
            initialComponent = await resolveInitialPage({
                payload: initial.component,
                styles,
                config,
                ssrState: window.pwax.ssrState,
            });
        } catch (error) {
            // Not fatal: without the resolved options the runtime simply mounts the page
            // on the client, exactly as it does with SSR switched off. The prerendered
            // markup is replaced rather than hydrated, which is slower and correct.
            console.error(
                'pwax: could not prepare the prerendered page for hydration, rendering it on the client instead',
                error
            );

            initialComponent = null;
        }
    }

    const page = createPageComponent({
        http,
        styles,
        config,
        initial,
        middleware: middlewareReady,
        prefetcher,
        templates: config.templates || {},
        progress: progressBar,
        initialComponent,
    });

    const createApp = initialComponent ? Vue.createSSRApp : Vue.createApp;

    const app = createApp({
        name: 'PwaxApp',
        template: (config.templates && config.templates.content) || DEFAULT_CONTENT,
    });

    const router = createRouter({ page, config });
    app.use(router);

    if (config.pinia !== false && typeof Pinia !== 'undefined' && Pinia.createPinia) {
        app.use(Pinia.createPinia());
    }

    for (const plugin of Object.values(plugins)) {
        if (plugin) {
            app.use(plugin.default || plugin);
        }
    }

    for (const [name, directive] of Object.entries(directives)) {
        if (directive) {
            app.directive(name, directive.default || directive);
        }
    }

    window.pwax.app = app;
    window.pwax.router = router;

    // Waiting for the router means the first paint already has the page component,
    // rather than briefly showing the shell and then swapping.
    await router.isReady();

    // Kept so the mount can be checked against it. Vue's recovery from a mismatch on the
    // container's first node is to build the application afresh *before* that node and
    // leave it where it is, which puts the whole page on screen twice.
    const served = initialComponent ? mount.firstElementChild : null;

    // The second argument is Vue's `isHydrate` flag: walk the existing nodes and attach
    // reactivity to them, rather than emptying the element and rendering into it.
    app.mount(mount, !!initialComponent);

    if (served && mount.firstElementChild !== served && served.parentNode === mount) {
        // Hydration did not adopt the markup the server sent: Vue built its own copy and
        // this is the original, still in the document and no longer referenced by anything.
        // Removing it is the difference between a page that looks broken and one that is
        // merely not hydrated, but the cause is worth fixing and is not visible from the
        // result — so it is named here rather than left to be discovered.
        served.remove();

        console.error(
            'pwax: the prerendered markup could not be hydrated, so the page was rendered ' +
                "again on the client and the server's copy was discarded. The usual cause is " +
                'something other than the prerendered markup inside the mount element — a ' +
                'published shell view that indents it, or adds a comment or a @yield around ' +
                'it. `<div id="pwax">` must contain the prerendered markup and nothing else.'
        );
    }

    mount.classList.remove('pwax-preloader');

    // Mounting replaces the spinner's markup, but a shell rendered by an older version of
    // this package — or a published one an application has customised — may still put the
    // loading semantics on the mount element itself. Left there they turn the application
    // root into a live region for the rest of the session: every reactive text change
    // announced, and the whole app labelled "Loading".
    for (const attribute of ['role', 'aria-live', 'aria-label', 'aria-busy']) {
        mount.removeAttribute(attribute);
    }

    document.documentElement.classList.add('pwax-ready');

    document.dispatchEvent(new CustomEvent('pwax:ready', { detail: { app, router } }));

    if (config.serviceWorker) {
        registerServiceWorker(config.serviceWorker, { scope: config.serviceWorkerScope || '/' });
    }

    // Published last, so a reboot called during boot does not see a half-built object.
    // `boot()` rebuilds `window.pwax` from scratch, so this assignment is what makes
    // `start` survive the reboot it triggers — each boot re-arms it.
    window.pwax.start = reboot;
}

function fail(error) {
    console.error('pwax: failed to start', error);

    // Re-armed here as well as at the end of `boot()`. A boot that threw — Vue not loaded,
    // a plugin module that 404'd, a mount element that was not there yet — never reached
    // the assignment at the end, so the one documented way to try again was missing from
    // exactly the situation it exists for. `window.pwax` may itself be absent if the
    // failure came before it was published, so this establishes it rather than assuming it.
    window.pwax = { ...(window.pwax || {}), start: reboot };

    const mount = document.getElementById('pwax');

    if (!mount) {
        return;
    }

    mount.classList.remove('pwax-preloader');

    // Built by hand rather than through the page component, because whatever failed may be
    // the page component. Nothing here is interpolated: the error goes to the console for
    // whoever is debugging and is deliberately not shown, since a runtime failure message
    // says more about the application than a visitor should be told.
    mount.innerHTML =
        '<div class="pwax-screen pwax-error" role="alert"><div class="pwax-screen__panel">' +
        '<p class="pwax-screen__code">Application</p>' +
        '<h1 class="pwax-screen__title">This app could not start</h1>' +
        '<p class="pwax-screen__message">Please reload the page. If the problem continues, ' +
        'contact the site administrator.</p>' +
        '<div class="pwax-screen__actions">' +
        '<button type="button" class="pwax-button pwax-reload">Reload</button>' +
        '</div></div></div>';

    // Bound, not written as an `onclick` attribute: the Content-Security-Policy this
    // package documents has no `unsafe-inline`, and an inline handler would be dropped —
    // leaving a button that looks like the way out and does nothing.
    mount.querySelector('.pwax-reload')?.addEventListener('click', () => window.location.reload());
}

/**
 * Reboot the runtime: unmount the current Vue app and re-initialise.
 *
 * Rarely needed — the runtime is designed to run for the life of the page — but it is
 * the supported way to recover from a hot-reload in development or to apply a
 * configuration change without a full page reload.
 *
 * Unmounts the existing app first so no orphaned Vue instance or duplicate event
 * listener survives. The module cache is reset too: a component edited on the server
 * after the first boot should not be served from the in-memory cache on reboot.
 *
 * Returns a Promise that resolves when the reboot is complete, so a caller can await
 * it in a test or a scripted workflow. Failures go through the same `fail()` path as
 * the initial boot.
 */
function reboot() {
    try {
        window.pwax?.app?.unmount?.();
    } catch {
        // An unmount that throws is not a reason to abort the reboot: the old app is
        // being discarded either way, and the new boot builds a fresh one.
    }

    document.documentElement.classList.remove('pwax-ready');

    resetModuleCache();

    return boot().catch(fail);
}

function start() {
    boot().catch(fail);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
