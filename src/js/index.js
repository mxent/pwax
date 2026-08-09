/**
 * Pwax client runtime entry point.
 *
 * Boot order matters and is deliberately flat: read config, build the pieces, mount.
 * Everything the server needs to say is in two JSON islands, so this file never has to
 * be generated or interpolated.
 */

import { createComponentLoader } from './components.js';
import { loadConfig, loadInitialPayload } from './config.js';
import { resolveExtensions } from './extensions.js';
import { createHttp } from './http.js';
import { importModule } from './modules.js';
import { createPageComponent } from './page.js';
import { createRouter } from './router.js';
import { createServiceWorkerApi, registerServiceWorker } from './serviceWorker.js';
import { createStyleManager } from './styles.js';

const DEFAULT_CONTENT = '<main><router-view></router-view></main>';

async function boot() {
    const config = loadConfig();
    const initial = loadInitialPayload();

    const http = createHttp(config);
    const styles = createStyleManager(document);
    const loader = createComponentLoader({ styles, nonce: config.nonce });

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
    };

    if (typeof Vue === 'undefined') {
        throw new Error(
            'pwax: Vue is not loaded. The Vue script tag must come before pwax.js, and it must be ' +
                'the full build (vue.global.prod.js) — the runtime-only build cannot compile templates.'
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
        templates: config.templates || {},
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
    document.documentElement.classList.add('pwax-ready');

    document.dispatchEvent(new CustomEvent('pwax:ready', { detail: { app, router } }));

    if (config.serviceWorker) {
        registerServiceWorker(config.serviceWorker, { scope: config.serviceWorkerScope || '/' });
    }
}

function fail(error) {
    console.error('pwax: failed to start', error);

    const mount = document.getElementById('pwax');

    if (!mount) {
        return;
    }

    mount.classList.remove('pwax-preloader');
    mount.innerHTML =
        '<div class="pwax-error" role="alert"><h1>This app could not start</h1>' +
        '<p>Please reload the page. If the problem continues, contact the site administrator.</p></div>';
}

function start() {
    boot().catch(fail);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
