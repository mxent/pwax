/**
 * The routed page component.
 *
 * It owns one job: given a URL, fetch the component the server has for it, mount it,
 * and keep the loading and error states honest while that happens.
 */

import { HttpError } from './http.js';
import { importInlineModule, importModule, styleMetadata, toComponentOptions } from './modules.js';

const PAGE_STYLE_KEY = 'pwax:page';

const DEFAULT_LOADER = '<div class="pwax-loading" role="status" aria-live="polite">Loading…</div>';

const DEFAULT_ERROR = `
    <div class="pwax-error" role="alert">
        <h1 v-text="error.statusText"></h1>
        <p v-text="error.message"></p>
        <button type="button" class="pwax-retry" @click="retry">Try again</button>
    </div>
`;

export function createPageComponent({
    http,
    styles,
    config,
    initial,
    middleware = {},
    templates = {},
}) {
    let initialPayload = initial;

    return {
        name: 'PwaxPage',

        // Loader and error markup come from the server so they stay customisable through
        // Blade, while this bundle itself remains static and cacheable.
        template: `
            <template v-if="error">${templates.error || DEFAULT_ERROR}</template>
            <template v-else-if="loading">${templates.loader || DEFAULT_LOADER}</template>
            <component v-else :is="component"></component>
        `,

        data() {
            return {
                component: null,
                loading: true,
                error: null,
                currentPath: null,
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

                const controller = new AbortController();
                this.controller = controller;

                try {
                    const payload = await http.json(path, { signal: controller.signal });

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
                    if (error instanceof HttpError && error.status === 419) {
                        window.location.reload();
                        return;
                    }

                    this.fail(error);
                } finally {
                    if (this.controller === controller) {
                        this.controller = null;
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
                    // navigate to afterwards, which is worse than never setting one.
                    if (payload.title) {
                        document.title = payload.title;
                    }

                    this.component = Vue.shallowRef(
                        Vue.defineAsyncComponent(() => Promise.resolve(options))
                    );
                    this.loading = false;

                    this.$nextTick(() => {
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
                let redirected = false;

                for (const name of names) {
                    const fn = middleware[name];

                    if (typeof fn !== 'function') {
                        console.warn(`pwax: unknown middleware "${name}"`);
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
