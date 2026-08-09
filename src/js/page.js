/**
 * The routed page component.
 *
 * It owns one job: given a URL, fetch the component the server has for it, mount it,
 * and keep the loading and error states honest while that happens.
 */

import { HttpError } from './http.js';
import { importInlineModule, importModule, styleMetadata, toComponentOptions } from './modules.js';

const PAGE_STYLE_KEY = 'pwax:page';

const DEFAULT_LOADER = '<div class="pwax-loading" role="status">Loading…</div>';

/**
 * One reload per tab for an expired CSRF token.
 *
 * Reloading to pick up a fresh token assumes the reload reaches the server. It does not
 * under `navigation_strategy => 'app-shell'`, where the worker answers navigations from
 * disk — so the same stale token comes back and the page reloads in a loop. `sessionStorage`
 * because the flag has to survive the reload it guards, and has to be gone in the next tab.
 */
const CSRF_RELOAD_KEY = 'pwax:csrf-reload';

const csrfReload = {
    /** True if a reload is still allowed, claiming it. */
    take() {
        try {
            if (window.sessionStorage.getItem(CSRF_RELOAD_KEY)) {
                return false;
            }

            window.sessionStorage.setItem(CSRF_RELOAD_KEY, '1');
        } catch {
            // Storage denied — a private window, a blocked third-party context. The
            // reload is the better failure of the two, so it goes ahead unguarded.
            return true;
        }

        return true;
    },

    clear() {
        try {
            window.sessionStorage.removeItem(CSRF_RELOAD_KEY);
        } catch {
            // Nothing was stored, so there is nothing to clear.
        }
    },
};

/**
 * The error screen, used when the server did not send one.
 *
 * A trimmed copy of `components/error.blade.php` — no home link, because this file cannot
 * know where home is. Keep the two in step: an application that publishes the view should
 * not get a visibly different screen from one that does not.
 */
const DEFAULT_ERROR = `
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

export function createPageComponent({
    http,
    styles,
    config,
    initial,
    // A map, or a promise of one. `index.js` hands over the promise so that resolving
    // module middleware does not hold up the first paint; `await` accepts both.
    middleware = {},
    templates = {},
    progress = null,
    transition = 'pwax-page',
}) {
    let initialPayload = initial;

    return {
        name: 'PwaxPage',

        /*
         * The page that is on screen stays on screen until the next one is ready.
         *
         * This used to render the loader *instead of* the current component the moment a
         * navigation started, so every click threw away what the visitor was reading,
         * collapsed the layout to the height of the word "Loading", and then expanded it
         * again — a flash of nothing between every two pages, at its worst on exactly the
         * connections where it matters most.
         *
         * Now `component` is only reassigned once the replacement has been fetched,
         * compiled and had its styles applied. During the fetch the old page is untouched
         * and the progress bar is the only thing that moves. The loader is still here for
         * the one case that has nothing to keep: the very first paint of an application
         * whose landing page was not inlined.
         *
         * Keyed on the path so Vue treats each page as a new element and runs the
         * transition; `mode="out-in"` because two pages briefly overlapping is a layout
         * jump, which is the thing being fixed.
         *
         * Loader and error markup come from the server so they stay customisable through
         * Blade, while this bundle itself remains static and cacheable.
         */
        template: `
            <template v-if="error">${templates.error || DEFAULT_ERROR}</template>
            <template v-else>
                <template v-if="!component">${templates.loader || DEFAULT_LOADER}</template>
                <transition name="${transition}" mode="out-in">
                    <component v-if="component" :is="component" :key="renderedPath"></component>
                </transition>
            </template>
        `,

        data() {
            return {
                component: null,
                loading: true,
                error: null,
                currentPath: null,
                // The path of the component actually on screen, which during a navigation
                // is not the path being navigated to. It is what keys the transition, so
                // it must change only when the rendered page does.
                renderedPath: null,
                // The first paint is not a navigation: the browser has just read the
                // document, so announcing it again would be noise.
                announced: false,
            };
        },

        created() {
            return this.visit(this.$route.fullPath);
        },

        beforeUnmount() {
            this.abort();
            styles.release(PAGE_STYLE_KEY);
        },

        async beforeRouteUpdate(to, from) {
            if (to.fullPath === from.fullPath) {
                return;
            }

            document.dispatchEvent(new CustomEvent('pwax:navigating', { detail: { to, from } }));

            await this.visit(to.fullPath);
        },

        methods: {
            abort() {
                if (this.controller) {
                    this.controller.abort();
                    this.controller = null;
                }
            },

            /**
             * Fetch and mount the component for a path.
             */
            async visit(path) {
                // The server already embedded the component for the URL this page was
                // opened at, so the first render costs no request at all.
                if (initialPayload && initialPayload.url === path) {
                    const payload = initialPayload.component;
                    initialPayload = null;
                    this.currentPath = path;

                    return this.mount(payload);
                }

                this.abort();

                this.error = null;
                this.loading = true;
                progress?.start();

                const controller = new AbortController();
                this.controller = controller;

                try {
                    const payload = await http.json(path, { signal: controller.signal });

                    if (controller.signal.aborted) {
                        return;
                    }

                    this.adoptIdentity(payload);

                    if (payload && payload.__location) {
                        window.location.href = payload.__location;
                        return;
                    }

                    if (payload && payload.redirect) {
                        this.$router.replace(payload.redirect);
                        return;
                    }

                    this.currentPath = path;

                    // A page arrived, so whatever token this document holds is being
                    // accepted. Re-arms the one reload allowed for a 419 below.
                    csrfReload.clear();

                    await this.mount(payload);
                } catch (error) {
                    // An abort is the expected outcome of navigating away mid-flight,
                    // not a failure worth showing the user.
                    if (error && error.name === 'AbortError') {
                        return;
                    }

                    // An expired CSRF token cannot be recovered from in place: the token
                    // baked into this document is stale, so the page has to be reloaded
                    // to get a fresh one. The server cannot translate this for us —
                    // VerifyCsrfToken throws rather than returning a response, so it
                    // never reaches Pwax's middleware.
                    //
                    // Once, though. The reload only helps if it returns a *different*
                    // document, and under `navigation_strategy => 'app-shell'` it does
                    // not: the worker answers that navigation from disk, the same stale
                    // token comes back, and the page reloads forever. One attempt, then
                    // the error is shown like any other.
                    if (error instanceof HttpError && error.status === 419 && csrfReload.take()) {
                        window.location.reload();
                        return;
                    }

                    this.fail(error);
                } finally {
                    if (this.controller === controller) {
                        this.controller = null;

                        // Only the navigation still in flight finishes the bar. An
                        // aborted one has been replaced by another that is still running,
                        // and completing it there would flash the bar to full and start
                        // it again for every link clicked in quick succession.
                        progress?.done();
                    }
                }
            },

            /**
             * Follow the server's view of who is signed in.
             *
             * `config.identity` is read once from the shell's JSON island, and a Pwax
             * application can change who is signed in without ever loading another
             * document: `return redirect('/dashboard')` from a login controller is
             * translated into a client-side navigation on purpose, and that is the
             * documented behaviour.
             *
             * So the identity the runtime sends can be a whole session out of date — the
             * guest label, still attached to every request an authenticated user makes.
             * The service worker names its caches from it, which means those pages were
             * being filed in the bucket every guest on the device can read. Worse, the
             * documented sign-out call, `forgetIdentity(window.pwax.config.identity)`,
             * read the same stale value and quietly cleared nothing.
             *
             * Every page payload now carries the identity it was actually rendered for, so
             * the correction costs no extra request. `http.headers()` reads `config` per
             * call, so assigning here is all the plumbing there is.
             */
            adoptIdentity(payload) {
                if (!payload || !Object.prototype.hasOwnProperty.call(payload, 'identity')) {
                    return;
                }

                const identity = payload.identity || null;

                if (identity === config.identity) {
                    return;
                }

                config.identity = identity;

                // Anything the previous identity accumulated is now unreachable under the
                // new name anyway, but a sign-out should not leave it on the device.
                document.dispatchEvent(new CustomEvent('pwax:identity', { detail: { identity } }));
            },

            /**
             * Turn a payload into a mounted component.
             */
            async mount(payload) {
                if (!payload) {
                    this.fail(new Error('pwax: empty component payload'));
                    return;
                }

                try {
                    // Page-level external assets are known up front, so unlike imported
                    // components these are in place before the module evaluates.
                    await Promise.all([
                        ...(payload.styles || []).map((href) => styles.link(href)),
                        ...(payload.scripts || []).map((src) => styles.script(src)),
                    ]);

                    // The previous page's stylesheet goes only after the next one is
                    // ready, so the swap never flashes unstyled content.
                    const previous = this.mountedStyleKey;

                    const options = await this.toOptions(payload);

                    styles.acquire(PAGE_STYLE_KEY, payload.style || '', { nonce: config.nonce });
                    this.mountedStyleKey = PAGE_STYLE_KEY;

                    if (previous) {
                        styles.release(previous);
                    }

                    if (await this.runMiddleware(options)) {
                        return;
                    }

                    // Set here rather than only in the shell. A title rendered server-side
                    // is right for the page you landed on and wrong for every page you
                    // navigate to afterwards, which is worse than never setting one.
                    if (payload.title) {
                        document.title = payload.title;
                    }

                    // Finished before the swap, not alongside it. The bar completing is
                    // what says the waiting is over; the fade is what says the page has
                    // changed. Running them in that order reads as one sequence rather
                    // than two things happening at once.
                    progress?.done();

                    // The swap, and the only point at which the page on screen changes.
                    // Everything above this line ran while the previous page was still
                    // rendered: the fetch, the compile, the external assets, the
                    // stylesheet. `renderedPath` moves with it, because it keys the
                    // transition and must not change while a navigation is merely in
                    // flight — a failed one leaves the visitor where they were.
                    this.component = Vue.shallowRef(
                        Vue.defineAsyncComponent(() => Promise.resolve(options))
                    );
                    this.renderedPath = this.currentPath;
                    this.loading = false;

                    this.$nextTick(() => {
                        this.announce();

                        document.dispatchEvent(
                            new CustomEvent('pwax:navigated', {
                                detail: { component: options, path: this.currentPath },
                            })
                        );
                    });
                } catch (error) {
                    this.fail(error);
                }
            },

            /**
             * Tell a screen reader the page changed.
             *
             * A full navigation announces itself: the browser resets focus and reads the
             * new document. A router does neither. It swaps the DOM under a user who is
             * given no signal that anything happened, and leaves focus wherever the link
             * they followed used to be — which, once that link is gone, is the top of the
             * document with nothing selected.
             *
             * Announcing the title is the smallest thing that restores the signal, and
             * the title is already correct here because `mount()` has just set it.
             * Nothing is announced for the first paint: the browser has just read the
             * document, and repeating it is noise.
             */
            announce() {
                if (!this.announced) {
                    this.announced = true;

                    return;
                }

                const announcer = document.getElementById('pwax-announcer');

                if (announcer) {
                    // Cleared first. A live region only announces a *change*, so
                    // navigating twice to pages with the same title would otherwise be
                    // read once.
                    announcer.textContent = '';
                    announcer.textContent = document.title;
                }
            },

            /**
             * Turn a payload into Vue component options.
             *
             * `module` is present only for components addressable by URL alone. A page
             * rendered with controller data is not, so it ships its script inline.
             */
            async toOptions(payload) {
                if (payload.module) {
                    const module = await importModule(payload.module);
                    const options = toComponentOptions(module);
                    const meta = styleMetadata(module);

                    if (!options.template && payload.template) {
                        options.template = payload.template;
                    }

                    if (!payload.style && meta.style) {
                        payload.style = meta.style;
                    }

                    return options;
                }

                if (!payload.script) {
                    return { template: payload.template || '' };
                }

                const module = await importInlineModule(
                    payload.script,
                    payload.hash || payload.script.length
                );
                const options = toComponentOptions(module);

                if (!options.template) {
                    options.template = payload.template || '';
                }

                return options;
            },

            /**
             * Run any registered client middleware, letting one of them redirect.
             *
             * @returns {Promise<boolean>} true when a middleware took over navigation
             */
            async runMiddleware(options) {
                const names = options.middleware || [];

                if (!names.length) {
                    return false;
                }

                // Awaited here rather than before the application mounted. Middleware is
                // only consulted once a page's options are in hand, so gating first paint
                // on a module fetch it does not need was pure delay.
                const registered = await middleware;
                let redirected = false;

                for (const name of names) {
                    const fn = registered[name];

                    if (typeof fn !== 'function') {
                        // The name and where it was asked for. Reporting the name alone
                        // points at `middleware_js` in config, which is usually correct
                        // and occasionally a red herring — the entry can be present and
                        // have failed to load, and then the only way to find which page
                        // is affected is to guess.
                        console.warn(
                            `pwax: unknown middleware "${name}" on ${this.currentPath || 'this page'}. ` +
                                'Check that it is listed in pwax.middleware_js and that its module loaded.'
                        );
                        continue;
                    }

                    await fn({
                        component: options,
                        meta: options.meta || {},
                        to: this.$route,
                        redirect: (path) => {
                            redirected = true;
                            this.$router.push(path);
                        },
                    });

                    if (redirected) {
                        return true;
                    }
                }

                return false;
            },

            fail(error) {
                const status = error instanceof HttpError ? error.status : null;

                this.error = {
                    status: status ?? 'Error',
                    statusText: this.titleFor(error, status),
                    message: this.messageFor(error, status),
                };

                this.loading = false;

                document.dispatchEvent(
                    new CustomEvent('pwax:error', { detail: { error, status } })
                );
            },

            titleFor(error, status) {
                if (status === 404) return 'Page not found';
                if (status === 403) return 'Not allowed';
                if (status === 401) return 'Sign in required';
                if (status && status >= 500) return 'Something went wrong';
                if (!status) return 'Connection problem';
                return error.statusText || 'Error';
            },

            messageFor(error, status) {
                if (status === 404) return 'The page you asked for does not exist.';
                if (status === 403) return 'You do not have access to this page.';
                if (status === 401) return 'Please sign in and try again.';
                if (status && status >= 500)
                    return 'The server could not load this page. Please try again.';
                if (!status) return 'This page needs an internet connection to load.';
                return 'The page could not be loaded.';
            },

            retry() {
                return this.visit(this.$route.fullPath);
            },
        },
    };
}
