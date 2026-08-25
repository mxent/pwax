/**
 * The routed page component.
 *
 * It owns one job: given a URL, fetch the component the server has for it, mount it,
 * and keep the loading and error states honest while that happens.
 */

import { applyHead } from './head.js';
import { HttpError } from './http.js';
import { pageTemplate } from './pageTemplate.mjs';
import {
    ensureRenderable,
    importInlineModule,
    importModule,
    styleMetadata,
    toComponentOptions,
} from './modules.js';

/**
 * The style manager key for a page's own stylesheet.
 *
 * Keyed on the component, not on the slot. This was the constant `pwax:page`, which reads
 * like an identity and is not one: the manager counts references per key, so acquiring the
 * next page's stylesheet under the same key as the current one merely incremented a counter
 * and left the *previous* page's CSS in the document — and `mount()` acquires before it
 * releases, deliberately, so that the swap never flashes unstyled content. The net effect
 * was that from the second page onward every visitor saw the first page's rules and none of
 * their own. Distinct keys make the acquire/release pair do what it says, and keep the
 * overlap that avoids the flash.
 *
 * `hash` is the component digest the payload always carries; the style's length is the
 * fallback, matching how `toOptions()` keys the inline module cache.
 */
function pageStyleKey(payload) {
    return 'pwax:page:' + (payload?.hash || (payload?.style || '').length);
}

/**
 * The identity Vue keys a page's instance on.
 *
 * Not the path, which is the obvious choice and the wrong one. `<KeepAlive>` caches by the
 * vnode's key, and reuses a cached instance by patching the old vnode against the new one
 * — which throws outright if the component *type* differs. So a key must change whenever
 * the type does, and the type is a fresh object every time a page is compiled.
 *
 * Keying on the path alone tied `<KeepAlive>`'s cache to this module's, and left them free
 * to disagree. They did, on the one operation an application is told to reach for:
 * `window.pwax.restore.forget(path)` drops the payload so the page is fetched and compiled
 * afresh, while `<KeepAlive>` still held an instance built from the previous compile under
 * the same key — and the next visit back to that page killed the application. `clear()`
 * did the same thing to every page at once, and the two caches falling out of step through
 * ordinary eviction would have done it eventually without either being called.
 *
 * Keyed on the options object instead, the question does not arise. A page restored from
 * the store carries the same object and so the same key, and `<KeepAlive>` reuses its
 * instance; a page compiled afresh gets a new key, and `<KeepAlive>` builds a new instance
 * beside the old one rather than mistaking one for the other. The stale entry is evicted in
 * its own time by the `max` the two caches share.
 *
 * A `WeakMap`, so an options object that has been dropped everywhere else takes its key
 * with it. The path is in the key only to make it legible in devtools.
 */
const pageKeys = new WeakMap();
let pageKeySequence = 0;

function pageKey(options, path) {
    let key = pageKeys.get(options);

    if (key === undefined) {
        key = `${path}#${++pageKeySequence}`;
        pageKeys.set(options, key);
    }

    return key;
}

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
 * Turn a payload into Vue component options.
 *
 * `module` is present only for components addressable by URL alone. A page rendered with
 * controller data is not, so it ships its script inline.
 *
 * Module scope rather than a method: it reads no instance state, and nothing about it
 * needs one.
 */
async function toOptions(payload) {
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

    const module = await importInlineModule(payload.script, payload.hash || payload.script.length);
    const options = toComponentOptions(module);

    // A page's precompiled render function travels inside its inline script, so
    // `toComponentOptions` has already found it; the template is the fallback for when
    // there is none.
    if (!options.template && !options.render) {
        options.template = payload.template || '';
    }

    return ensureRenderable(options);
}

export function createPageComponent({
    http,
    styles,
    config,
    initial,
    // A map, or a promise of one. `index.js` hands over the promise so that resolving
    // module middleware does not hold up the first paint; `await` accepts both.
    middleware = {},
    prefetcher = null,
    restore = null,
    templates = {},
    progress = null,
}) {
    // Consumed by the first `visit()` and cleared, so a later navigation back to this URL
    // fetches rather than replaying the payload the document was served with.
    let initialPayload = initial;

    /*
     * How many page instances `<KeepAlive>` may hold, or 0 for none.
     *
     * Gated on the restoration cache rather than configured apart from it, because
     * `<KeepAlive>` cannot work without it. Vue reuses a cached instance only when the
     * component *type object* is identical between the two visits: `patch()` compares
     * `n1.type === n2.type`, and a type that differs tears the instance down and builds a
     * new one — in a `<KeepAlive>` it throws outright. Every compile produces a fresh
     * options object, so the only thing that can supply a stable identity is a store that
     * held the first one. That store is `restore`.
     */
    const retain =
        config.restore && config.restore.state !== false ? config.restore.entries || 0 : 0;

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
         *
         * Built by `pageTemplate()` rather than written here — see that module's docblock
         * for why the fragment structure is what it is.
         */
        template: pageTemplate(templates, retain),

        data() {
            return {
                component: null,
                loading: true,
                error: null,
                currentPath: null,
                // What Vue keys the page instance on: the identity of the component
                // actually on screen, which during a navigation is not the one being
                // navigated to. It moves only when the rendered page does, so a failed
                // navigation leaves the visitor exactly where they were.
                //
                // An identity rather than the path, because two visits to one path can be
                // two different components — see `pageKey()` — and the moment those share
                // a key, `<KeepAlive>` reuses an instance built from the other one.
                renderedKey: null,
                // Which of the two page slots renders: inside `<KeepAlive>`, or beside
                // it. Moved with the swap rather than ahead of it, so a page that opts out
                // cannot switch the slot out from under the page still on screen.
                keepState: true,
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

            // Whatever this page acquired, which is not a constant: the key carries the
            // component's digest so each page's stylesheet has its own identity.
            if (this.mountedStyleKey) {
                styles.release(this.mountedStyleKey);
            }
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

                /*
                 * Back or forward to a page this document has already rendered.
                 *
                 * `take()` answers only for a navigation the browser started, so a link
                 * click to a URL that happens to be held still fetches — going back asks
                 * for the page you were on, clicking a link asks for the page as it is
                 * now. See `restore.js` for why those are different questions.
                 *
                 * Nothing between here and `mount()` touches the network, so `loading`
                 * is never set and the progress bar never starts: there is no wait to
                 * report. The page on screen stays there for the one microtask
                 * `mount()` needs, exactly as it does for a fetched page.
                 */
                const restored = restore?.take(path);

                if (restored) {
                    // A navigation already in flight is now irrelevant — the visitor has
                    // gone back while it was running — and its payload must not be
                    // allowed to land on top of the restored page.
                    this.abort();

                    this.error = null;
                    this.currentPath = path;

                    return this.mount(restored.payload, restored.options);
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
            async mount(payload, retained = null) {
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

                    /*
                     * The *same* options object as last time, when this page is being
                     * restored. Not an optimisation: it is what lets `<KeepAlive>` reuse
                     * the instance, and therefore what preserves the form the visitor was
                     * filling in. Compiling again would produce an equal-but-distinct type
                     * and Vue would throw rather than reuse.
                     */
                    const options = retained || (await toOptions(payload));

                    const key = pageStyleKey(payload);

                    styles.acquire(key, payload.style || '', { nonce: config.nonce });
                    this.mountedStyleKey = key;

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

                    applyHead(payload.head, { nonce: config.nonce });

                    // Finished before the swap, not alongside it. The bar completing is
                    // what says the waiting is over; the fade is what says the page has
                    // changed. Running them in that order reads as one sequence rather
                    // than two things happening at once.
                    progress?.done();

                    // The swap, and the only point at which the page on screen changes.
                    // Everything above this line ran while the previous page was still
                    // rendered: the fetch, the compile, the external assets, the
                    // stylesheet. `renderedKey` moves with it, because it keys the
                    // transition and must not change while a navigation is merely in
                    // flight — a failed one leaves the visitor where they were.
                    // `markRaw`, not `defineAsyncComponent`. The options object was
                    // already resolved by `toOptions()` above, so wrapping it in an async
                    // component asked Vue to await a promise that was never pending: an
                    // extra microtask and an extra render pass in which `component` is
                    // truthy but draws nothing. Worse, it minted a *new component type* on
                    // every navigation, so returning to a path already visited unmounted
                    // and rebuilt the page from scratch even though the key on the
                    // `<component>` had not changed.
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
                        this.renderedKey = pageKey(options, this.currentPath);
                        this.keepState = options.restore !== false;
                        this.loading = false;
                    };

                    const transitionReady = withViewTransition(swap);

                    // The post-swap work — announcing the new page, dispatching the
                    // event — depends on the transition having settled, so `await` it
                    // before either. The browser resolves the returned promise once the
                    // new pseudo-elements have been committed; without the API, the
                    // await is a no-op on a synchronous value.
                    await transitionReady;

                    /*
                     * Kept for the back button, now that this page is definitely the one
                     * on screen. Recorded after the swap rather than after the fetch so
                     * that a payload which failed to compile, or a middleware that
                     * redirected away from it, leaves nothing behind to restore.
                     *
                     * A page opts out by declaring `restore: false` in its script, the
                     * same way it declares `middleware`. That is for a page whose content
                     * is only correct at the moment it was served — a one-time token, a
                     * checkout step, a flash of something that has since been read.
                     */
                    if (options.restore !== false) {
                        restore?.remember(this.currentPath, { payload, options });
                    }

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
