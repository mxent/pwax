/**
 * The routed page component.
 *
 * It owns one job: given a URL, fetch the component the server has for it, mount it,
 * and keep the loading and error states honest while that happens.
 */

import { applyHead } from './head.js';
import { HttpError } from './http.js';
import {
    ensureRenderable,
    importInlineModule,
    importModule,
    styleMetadata,
    toComponentOptions,
} from './modules.js';

const PAGE_STYLE_KEY = 'pwax:page';

const DEFAULT_LOADER = '<div class="pwax-loading" role="status">Loading…</div>';

/**
 * Run a DOM mutation inside `document.startViewTransition` when the browser supports it.
 *
 * The View Transitions API snapshots the current document, lets the callback commit a
 * change, and cross-fades between the snapshot and the new state in a single frame. That
 * is exactly what page transitions want: two frames painted at once, no empty
 * router-view between them, and no interleaved unmount/mount that shows in a layout
 * jump. Without the API, the callback runs synchronously, which is the previous
 * behaviour preserved.
 *
 * The snapshot is taken immediately and the callback is awaited, so all of the page
 * prep — fetch, compile, stylesheet, scripts — can finish before the new page is even
 * drawn. `waitForReady` is the default: the browser holds the snapshot visible until
 * the callback returns a promise that resolves, which is what makes the swap a single
 * frame.
 *
 * `prefers-reduced-motion` is respected by the browser itself; the transition runs, but
 * the cross-fade is replaced with a synchronous swap.
 */
function withViewTransition(update) {
    if (typeof document !== 'undefined' && typeof document.startViewTransition === 'function') {
        return document.startViewTransition(update);
    }

    return update();
}

/**
 * One reload per tab for an expired CSRF token.
 *
 * Reloading to pick up a fresh token assumes the reload reaches the server. It does not
 * under `navigation_strategy => 'cache-first'`, where the worker answers navigations from
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
    prefetcher = null,
    templates = {},
    progress = null,
}) {
    let initialPayload = initial;

    // The server's prerendered state for the initial page, or null. Set by `index.js`
    // before the page component is mounted, and consumed in `mount()` so the first
    // render hydrates with the same values the server rendered with.
    let ssrState = null;

    return {
        /**
         * Set the SSR state for the initial page.
         *
         * Called once by `index.js` after it reads the `pwax-state` island. A page that
         * was not prerendered receives null and behaves exactly as before.
         *
         * @param {Record<string, unknown>|null} state
         */
        setSsrState(state) {
            ssrState = state;
        },

        /**
         * Resolve the initial page's options before mount, for hydration.
         *
         * When the server prerendered the page, `createSSRApp` needs the page component
         * already in place at mount time so the virtual DOM it produces matches the
         * server-rendered HTML it is hydrating. This resolves the inlined initial
         * payload's options, applies the SSR state, and sets `this.component` — without
         * the DOM swap `mount()` would do, because there is nothing to swap: the DOM is
         * already there. Returns true when it consumed the initial payload (hydration
         * will proceed), false when there was none (the caller falls back to `createApp`).
         *
         * @returns {Promise<boolean>}
         */
        async prepareInitial() {
            if (!initialPayload || !initialPayload.component) {
                return false;
            }

            // The URL lives on the outer payload, not on the component itself — same
            // shape `visit()` reads. `currentPath` is what keys the transition and what
            // `beforeRouteUpdate` compares against, so it must match the route.
            this.currentPath = initialPayload.url || '';

            const payload = initialPayload.component;
            initialPayload = null;

            try {
                await Promise.all([
                    ...(payload.styles || []).map((href) => styles.link(href)),
                    ...(payload.scripts || []).map((src) => styles.script(src)),
                ]);

                const options = await this.toOptions(payload);

                // Seed the component's `data()` with the server's resolved state so the
                // hydrated reactive values match the prerendered HTML. The author's own
                // `data()` runs first and wins for any key it sets; SSR state fills in
                // only what the author did not, which is the safe merge for hydration.
                if (ssrState && typeof options.data === 'function') {
                    const originalData = options.data;
                    options.data = function ssrHydrationData(...args) {
                        const own = originalData.apply(this, args);
                        return { ...ssrState, ...own };
                    };
                } else if (ssrState && typeof options.data !== 'function') {
                    options.data = () => ({ ...ssrState });
                }

                styles.acquire(PAGE_STYLE_KEY, payload.style || '', { nonce: config.nonce });
                this.mountedStyleKey = PAGE_STYLE_KEY;

                if (payload.title) {
                    document.title = payload.title;
                }

                applyHead(payload.head);

                this.component = Vue.markRaw(options);
                this.renderedPath = this.currentPath;
                this.loading = false;
                this.announced = true;

                return true;
            } catch (error) {
                this.fail(error);
                return false;
            }
        },
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
         * The DOM swap itself is wrapped in `document.startViewTransition` (see
         * `withViewTransition` below) so the browser snapshots the outgoing page,
         * commits the new one, and cross-fades between them in a single frame. Browsers
         * without the API fall back to a synchronous swap, which is what the old
         * `<transition mode="out-in">` did. The Vue transition component was removed
         * because two-phase mount/unmount was the source of the "empty router-view"
         * flicker; the browser's snapshot mechanism is the same idea, but it does not
         * interleave the two phases.
         *
         * Loader and error markup come from the server so they stay customisable through
         * Blade, while this bundle itself remains static and cacheable.
         */
        template: `
            <template v-if="error">${templates.error || DEFAULT_ERROR}</template>
            <template v-else>
                <template v-if="!component">${templates.loader || DEFAULT_LOADER}</template>
                <component v-if="component" :is="component" :key="renderedPath"></component>
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
            // When `prepareInitial()` already resolved and mounted the initial page for
            // hydration, the `created()` hook has nothing to fetch — the component is in
            // place and the DOM is already rendered. Skipping `visit()` is what makes
            // hydration a no-op for the first paint rather than a second render that
            // replaces the server's HTML.
            if (this.component) {
                return;
            }

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
                    // Taken if a pointer or a focus already started this request. Same
                    // request, sent earlier — so the visitor waits for whatever is left
                    // of it rather than for all of it.
                    const started = prefetcher?.take(path);

                    const payload = started
                        ? await started
                        : await http.json(path, { signal: controller.signal });

                    if (controller.signal.aborted) {
                        return;
                    }

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
                    // document, and under `navigation_strategy => 'cache-first'` it does
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
                    // navigate to afterwards, which is worse than never setting one — and
                    // the same is true of everything else that describes the document, so
                    // the description, canonical URL and Open Graph tags move with it.
                    if (payload.title) {
                        document.title = payload.title;
                    }

                    applyHead(payload.head);

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
                    // `markRaw`, not `defineAsyncComponent`. The options object was
                    // already resolved by `toOptions()` above, so wrapping it in an async
                    // component asked Vue to await a promise that was never pending: an
                    // extra microtask and an extra render pass in which `component` is
                    // truthy but draws nothing. Worse, it minted a *new component type* on
                    // every navigation, so returning to a path already visited unmounted
                    // and rebuilt the page from scratch even though `renderedPath` — the
                    // key on the `<component>` — had not changed.
                    //
                    // `markRaw` does what the `shallowRef` was reaching for: it keeps Vue
                    // from walking the options and making them reactive.
                    //
                    // The three mutations are batched inside `withViewTransition` so the
                    // browser snapshots the outgoing page, then commits the new page in
                    // a single frame. Without that, Vue's reactivity would tear the
                    // router-view down before the new component is in the DOM — that
                    // single-frame empty state is the flicker that motivated this change.
                    // With it, the browser holds the old page visible until the new one
                    // is ready, even when `transition.duration` is 0.
                    const swap = () => {
                        this.component = Vue.markRaw(options);
                        this.renderedPath = this.currentPath;
                        this.loading = false;
                    };

                    const transitionReady = withViewTransition(swap);

                    // The post-swap work — announcing the new page, dispatching the
                    // event — depends on the transition having settled, so `await` it
                    // before either. The browser resolves the returned promise once the
                    // new pseudo-elements have been committed; without the API, the
                    // await is a no-op on a synchronous value.
                    await transitionReady;

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
             * Tell a screen reader the page changed, and put focus where it belongs.
             *
             * A full navigation does both on its own: the browser resets focus to the top
             * of the new document and reads it. A router does neither. It swaps the DOM
             * under a user who is given no signal that anything happened, and leaves focus
             * wherever the link they followed used to be — which, once that link is gone,
             * is the body, so the next Tab starts from the top of the page and the next
             * screen-reader command reads from wherever the cursor was stranded.
             *
             * Announcing the title restores the signal. Moving focus to the application
             * root restores the position, which is what makes the skip link and ordinary
             * Tab order behave the way they do on a server-rendered site.
             *
             * Neither happens on the first paint: the browser has just done both itself,
             * and repeating them steals focus from a visitor who has already started
             * interacting.
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

                this.refocus();
            },

            /**
             * Move focus to the top of the newly rendered page.
             *
             * The mount element carries `tabindex="-1"` so it can receive focus without
             * entering the tab order. `preventScroll` because the router's own
             * `scrollBehavior` has already decided where this navigation should land —
             * restoring a saved position, or jumping to a hash — and focusing an element
             * would otherwise scroll it into view and overrule that.
             *
             * A page that wants focus somewhere more specific can take it in `mounted()`;
             * this runs first.
             */
            refocus() {
                // Only when nothing has claimed focus. The new page's `mounted()` has
                // already run by this point, so a page that focused a search field or a
                // first input meant to — taking it back would be worse than not moving.
                if (document.activeElement && document.activeElement !== document.body) {
                    return;
                }

                document.getElementById(config.mount || 'pwax')?.focus?.({ preventScroll: true });
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

                    if (!options.template && !options.render && payload.template) {
                        options.template = payload.template;
                    }

                    if (!payload.style && meta.style) {
                        payload.style = meta.style;
                    }

                    return ensureRenderable(options);
                }

                if (!payload.script) {
                    return ensureRenderable({ template: payload.template || '' });
                }

                const module = await importInlineModule(
                    payload.script,
                    payload.hash || payload.script.length
                );
                const options = toComponentOptions(module);

                // A page's precompiled render function travels inside its inline script,
                // so `toComponentOptions` has already found it; the template is the
                // fallback for when there is none.
                if (!options.template && !options.render) {
                    options.template = payload.template || '';
                }

                return ensureRenderable(options);
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
                        // points at `vue.middleware` in config, which is usually correct
                        // and occasionally a red herring — the entry can be present and
                        // have failed to load, and then the only way to find which page
                        // is affected is to guess.
                        console.warn(
                            `pwax: unknown middleware "${name}" on ${this.currentPath || 'this page'}. ` +
                                'Check that it is listed in pwax.vue.middleware and that its module loaded.'
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

                // Anything that is not an HTTP failure is shown to the visitor as a
                // connection problem, because nine times out of ten that is what it is.
                // The tenth is a bug or a misconfiguration — a component that will not
                // compile, a middleware that threw — and the console is the only place
                // left where it can say what actually happened.
                if (status === null) {
                    console.error('pwax: navigation failed.', error);
                }

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
