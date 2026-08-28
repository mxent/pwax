/**
 * `<PwaxJson>` — rendering a JSON document with the on-demand renderer.
 *
 * The renderer itself is `dist/pwax-json.js`, a second bundle carrying
 * `@json-render/vue`, `@json-render/core` and zod. It is 82 kB gzipped against this
 * runtime's 9.7 kB, so it is fetched the first time a `<PwaxJson>` renders and never
 * on a page that has none. Everything in this file is the part that has to be in the
 * runtime: the loader, the catalog wiring, the built-in actions, and the component
 * itself, which is registered globally so a Blade component can write `<PwaxJson>`
 * with nothing to import.
 *
 * The catalog is `pwax.json.components`, resolved server-side into the same
 * `{type: 'module'|'global'}` shape as `pwax.vue.plugins` and walked here — a
 * configured string is never evaluated. See `src/js/extensions.js` for the reasoning.
 */

import { resolveExtensions } from './extensions.js';

/**
 * Load the renderer bundle, at most once.
 *
 * A plain `<script>` rather than a dynamic `import()`, because the bundle is an IIFE
 * like the runtime itself, and because a script tag can carry the CSP nonce. The
 * promise is memoised rather than the element: two `<PwaxJson>` components mounting in
 * the same tick must share one request, and the second must wait for it rather than
 * find a tag in the document that has not executed yet.
 *
 * A failure clears the memo. A renderer that failed to load because the network
 * dropped should be retried by the next component that needs it, not remembered as
 * broken for the rest of the session.
 *
 * @param {string} url
 * @param {string|null} nonce
 */
function createBundleLoader(url, nonce) {
    /** @type {Promise<any>|null} */
    let pending = null;

    return function ready() {
        if (pending) {
            return pending;
        }

        pending = new Promise((resolve, reject) => {
            if (!url) {
                reject(
                    new Error(
                        'pwax: <PwaxJson> was rendered but pwax.json.enabled is false, so the ' +
                            'renderer is not served. Turn it on in config/pwax.php.'
                    )
                );

                return;
            }

            const script = document.createElement('script');
            script.src = url;
            script.setAttribute('data-pwax-script', '');

            if (nonce) {
                script.nonce = nonce;
            }

            script.addEventListener('load', () => {
                if (!window.PwaxJson) {
                    reject(new Error(`pwax: ${url} loaded but did not publish PwaxJson.`));

                    return;
                }

                resolve(window.PwaxJson);
            });

            script.addEventListener('error', () =>
                reject(new Error(`pwax: failed to load the JSON renderer from ${url}.`))
            );

            document.head.appendChild(script);
        }).catch((error) => {
            pending = null;

            throw error;
        });

        return pending;
    };
}

/**
 * Warn about the two document shapes that render nothing and say nothing.
 *
 * Both are things the format appears to support and this version of the renderer does
 * not act on, which is the worst combination: a document that looks right, renders
 * blank, and logs nothing at all.
 *
 *   - `slots` on an element is never read. The renderer walks `children` only, so a
 *     document that puts its content under `slots` renders an empty component.
 *   - `repeat` repeats an element's `children`. On an element that has none it
 *     produces one empty row rather than the list the author meant.
 *
 * Once per mounted `<PwaxJson>`, over its own elements only — a walk of a handful of
 * objects, next to a render that is about to load a bundle.
 *
 * @param {any} json
 */
function warnAboutDocument(json) {
    const elements = (json && json.elements) || {};

    for (const [key, element] of Object.entries(elements)) {
        if (!element || typeof element !== 'object') {
            continue;
        }

        if (element.slots) {
            console.warn(
                `pwax: element "${key}" in this JSON document uses "slots", which the ` +
                    'renderer does not read — it will render empty. List the child keys ' +
                    'under "children" instead, and give the component a single <slot />.'
            );
        }

        if (element.repeat && !(element.children || []).length) {
            console.warn(
                `pwax: element "${key}" has a "repeat" but no "children". "repeat" repeats ` +
                    "an element's children, so it belongs on the container, not on the row."
            );
        }
    }
}

/**
 * Build the `<PwaxJson>` component and the machinery behind it.
 *
 * @param {{
 *   config: any,
 *   loader: {load: (url: string, exportName?: string) => Promise<any>},
 *   http: ReturnType<import('./http.js').createHttp>,
 *   sync: ReturnType<import('./sync.js').createSyncApi>,
 *   navigate: (path: string) => unknown,
 * }} deps
 */
export function createJson({ config, loader, http, sync, navigate }) {
    const settings = config.json || {};
    const enabled = settings.enabled !== false && Boolean(settings.runtime);
    const ready = createBundleLoader(settings.runtime, config.nonce);

    /**
     * What a document can do without the application writing a handler.
     *
     * These are Pwax semantics rather than the renderer's, which is why they live here
     * and not in the bundle: `navigate` has to go through the SPA router or it is a
     * full page load, and `submit` has to carry the CSRF token and be queued when the
     * connection is gone, both of which `window.pwax` already knows how to do.
     */
    const builtIn = {
        navigate: {
            description: 'Navigate to a path within the application.',
            handler: (params) => navigate((params && params.to) || '/'),
        },
        submit: {
            description: 'POST a payload to a URL, queued for later when offline.',
            handler: async (params) => {
                const url = params && params.url;

                if (!url) {
                    console.warn('pwax: the "submit" action needs a "url" parameter.');

                    return;
                }

                const options = {
                    method: (params && params.method) || 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify((params && params.data) || {}),
                };

                // Queued rather than failed when there is no connection, so a form
                // filled in on a train sends when the train leaves the tunnel. The queue
                // is the one `window.pwax.sync` already exposes to application code.
                //
                // `enqueue()` returns false when it had nowhere to store the request —
                // no Cache API, or a cross-origin URL it refuses to replay — and then
                // the request is sent normally, so the failure is the visible kind
                // rather than a write that quietly disappeared.
                if (sync && sync.supported && !navigator.onLine) {
                    if (await sync.enqueue(url, options)) {
                        return true;
                    }
                }

                return http.json(url, options);
            },
        },
        reload: {
            description: 'Reload the current page.',
            handler: () => window.location.reload(),
        },
    };

    /** Resolved application handlers, started once and shared. */
    let configured = null;

    function handlers() {
        if (!configured) {
            configured = resolveExtensions(settings.actions, loader);
        }

        return configured;
    }

    /**
     * One renderer per catalog subset, memoised.
     *
     * Keyed on the sorted names so the full catalog and a `:only` subset are built once
     * each rather than on every render. The wrappers inside the bundle are memoised on
     * the component too, so two subsets that share a component share its module and its
     * stylesheet.
     *
     * @type {Map<string, Promise<any>>}
     */
    const renderers = new Map();

    function rendererFor(only) {
        // An array is a restriction, whatever its length. Testing `only.length` instead
        // made `:only="[]"` mean "no restriction" — so a page narrowing the catalog from
        // a role or a feature flag was handed *all* of it on the one path where the
        // allowed list came back empty. This prop exists so a document nobody wrote by
        // hand cannot reach past a named subset; failing open is the one thing it must
        // never do.
        const names = Array.isArray(only) ? [...only].sort() : null;

        // Prefixed, so the restricted-to-nothing key (`only:`) cannot collide with the
        // unrestricted one.
        const key = names ? `only:${names.join('|')}` : '*';
        const cached = renderers.get(key);

        if (cached) {
            return cached;
        }

        const promise = Promise.all([ready(), handlers()]).then(([bundle, actions]) => {
            const components = {};

            for (const [name, entry] of Object.entries(settings.components || {})) {
                if (!names || names.includes(name)) {
                    components[name] = entry;
                }
            }

            // A name in `only` that matches nothing is a typo, and a silent one: the
            // subset is narrower than the author asked for, and the document then reports
            // an unknown component for something the catalog does contain.
            for (const name of names || []) {
                if (!(name in (settings.components || {}))) {
                    console.warn(
                        `pwax: <PwaxJson :only> names "${name}", which is not in the ` +
                            'catalog. Check pwax.json.components.'
                    );
                }
            }

            const declared = {};

            for (const [name, action] of Object.entries(builtIn)) {
                declared[name] = { description: action.description };
            }

            for (const name of Object.keys(actions)) {
                declared[name] = { description: '' };
            }

            return {
                ...bundle.createRenderer({
                    components,
                    actions: declared,
                    load: (url, exportName) => loader.load(url, exportName),
                }),
                actions,
            };
        });

        // Not cached if it failed, which is the same rule — and the same shape — as
        // `importModule()` in `modules.js`: a transient network error must not poison the
        // renderer for the rest of the session. `createBundleLoader` already clears its
        // own memo, and without this one the retry it exists for could never happen.
        renderers.set(
            key,
            promise.catch((error) => {
                renderers.delete(key);

                throw error;
            })
        );

        return renderers.get(key);
    }

    const PwaxJson = Vue.defineComponent({
        name: 'PwaxJson',

        props: {
            /** The document: `{root, elements, state?}`. */
            json: { type: Object, required: true },
            /** Initial state, when it is kept apart from the document. */
            state: { type: Object, default: null },
            /** Extra action handlers for this instance only. */
            handlers: { type: Object, default: () => ({}) },
            /**
             * Restrict this instance to a subset of the catalog.
             *
             * Read when the component mounts, not watched: it is a statement about what
             * this instance is allowed to draw, and a registry is built per subset. Change
             * it with a `:key` on the component if it genuinely has to vary.
             */
            only: { type: Array, default: null },
        },

        emits: ['action', 'state-change', 'error'],

        setup(props, { emit, slots }) {
            // `shallowRef`, not `ref`: these hold component options and a registry full
            // of component definitions, and making that tree reactive would cost far
            // more than the one swap it is here to trigger.
            const renderer = Vue.shallowRef(null);
            const failure = Vue.shallowRef(null);

            if (!enabled) {
                console.warn(
                    'pwax: <PwaxJson> was rendered but pwax.json.enabled is false. Turn it ' +
                        'on in config/pwax.php, or remove the component.'
                );
            } else {
                // Watched rather than called once. These guard rails are for documents
                // nobody hand-checked — generated ones — and a generated document is
                // precisely the thing swapped into `:json` after mount, so checking only
                // the first one missed the case the warnings were written for.
                Vue.watch(() => props.json, warnAboutDocument, { immediate: true });

                rendererFor(props.only)
                    .then((resolved) => {
                        renderer.value = Vue.markRaw(resolved);
                    })
                    .catch((error) => {
                        failure.value = error;
                        console.error('pwax: the JSON renderer could not start.', error);
                        emit('error', error);
                    });
            }

            return () => {
                // Nothing at all, and never the loading slot: turned off, there is nothing
                // on its way, and a spinner that runs for the life of the page is a worse
                // way to say so than the console line above.
                if (!enabled) {
                    return null;
                }

                if (failure.value) {
                    return slots.error ? slots.error({ error: failure.value }) : null;
                }

                if (!renderer.value) {
                    return slots.loading ? slots.loading() : null;
                }

                const { Root, actions } = renderer.value;

                const merged = {};

                for (const [name, action] of Object.entries(builtIn)) {
                    merged[name] = action.handler;
                }

                for (const [name, handler] of Object.entries(actions)) {
                    if (typeof handler === 'function') {
                        merged[name] = handler;
                    }
                }

                // Last, so a page can override anything — including a built-in — for its
                // own document without touching configuration.
                for (const [name, handler] of Object.entries(props.handlers || {})) {
                    merged[name] = handler;
                }

                return Vue.h(Root, {
                    spec: props.json,
                    state: props.state,
                    handlers: merged,
                    onAction: (name, params) => emit('action', name, params),
                    onStateChange: (changes) => emit('state-change', changes),
                });
            };
        },
    });

    return {
        PwaxJson,
        /** Load the renderer now, rather than on first render. */
        load: ready,
        /** The system prompt that constrains a model to the catalog. */
        prompt: (options) => rendererFor(null).then((r) => r.prompt(options)),
        /** The JSON Schema for a model that supports structured output. */
        jsonSchema: (options) => rendererFor(null).then((r) => r.jsonSchema(options)),
    };
}
