/**
 * Pwax client runtime entry point.
 *
 * Boot order matters and is deliberately flat: read config, build the pieces, mount.
 * Everything the server needs to say is in two JSON islands, so this file never has to
 * be generated or interpolated.
 */

import { resetModuleCache } from './modules.js';
import { createComponentLoader } from './components.js';
import { loadConfig, loadInitialPayload } from './config.js';
import { resolveExtensions } from './extensions.js';
import { createHttp } from './http.js';
import { createBadgeApi, createInstallApi, createStorageApi, watchInstall } from './install.js';
import { createLaunchApi, createShareApi, watchLaunch } from './launch.js';
import { importModule } from './modules.js';
import { createPushApi } from './push.js';
import { createPageComponent } from './page.js';
import { createPrefetcher } from './prefetch.js';
import { createProgress } from './progress.js';
import { createRouter } from './router.js';
import { createServiceWorkerApi, registerServiceWorker } from './serviceWorker.js';
import { createStyleManager } from './styles.js';
import { createSyncApi } from './sync.js';

const DEFAULT_CONTENT = '<main><router-view></router-view></main>';

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

    const page = createPageComponent({
        http,
        styles,
        config,
        initial,
        middleware: middlewareReady,
        prefetcher,
        templates: config.templates || {},
        progress: progressBar,
    });

    const app = Vue.createApp({
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

    const mount = document.getElementById(config.mount || 'pwax');

    if (!mount) {
        throw new Error(`pwax: mount element #${config.mount || 'pwax'} was not found.`);
    }

    // Waiting for the router means the first paint already has the page component,
    // rather than briefly showing the shell and then swapping.
    await router.isReady();

    app.mount(mount);
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
