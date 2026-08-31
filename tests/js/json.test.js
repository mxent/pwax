import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createJson } from '../../src/js/json.js';

/**
 * The runtime half of `<PwaxJson>`: loading the renderer, building the catalog, and the
 * actions Pwax provides on top of it.
 *
 * The renderer itself is stubbed here — `tests/js/jsonRender.test.js` runs the real one.
 * What is under test is everything that has to happen before it: that the bundle is
 * fetched once and only when needed, that the configured catalog reaches it unchanged,
 * and that the built-in actions do what their names say.
 */

/** Minimal stand-in for the global Vue build, recording what the component renders. */
function stubVue() {
    const Vue = {
        defineComponent: vi.fn((options) => options),
        shallowRef: vi.fn((value) => ({ value })),
        markRaw: vi.fn((value) => value),
        h: vi.fn((type, props) => ({ type, props })),
        // Runs the callback once for `immediate` and never again, which is what the
        // tests that are not about the watcher itself need. The one that is replaces it.
        watch: vi.fn((source, callback, options) => {
            if (options && options.immediate) {
                callback(source());
            }
        }),
    };

    vi.stubGlobal('Vue', Vue);

    return Vue;
}

/** A `window.PwaxJson` that records the arguments `createRenderer` was called with. */
function stubBundle() {
    const calls = [];

    const bundle = {
        calls,
        createRenderer: vi.fn((options) => {
            calls.push(options);

            return {
                Root: { name: 'StubRoot' },
                prompt: () => 'PROMPT',
                jsonSchema: () => ({ stub: true }),
            };
        }),
    };

    return bundle;
}

/**
 * Answer the script tag the loader appends.
 *
 * The real bundle publishes `window.PwaxJson` as it evaluates; jsdom does not run an
 * appended script, so this stands in for that and fires the event the loader waits on.
 */
function serveBundle(bundle, { fail = false } = {}) {
    const observer = new MutationObserver((records) => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node.tagName !== 'SCRIPT') {
                    continue;
                }

                if (!fail) {
                    window.PwaxJson = bundle;
                }

                node.dispatchEvent(new window.Event(fail ? 'error' : 'load'));
            }
        }
    });

    observer.observe(document.head, { childList: true });

    return () => observer.disconnect();
}

function config(overrides = {}) {
    return {
        nonce: null,
        json: {
            enabled: true,
            runtime: '/__pwax__/pwax-json.js?v=abc',
            components: {
                Card: { type: 'module', url: '/c/card.js', export: '' },
                Button: { type: 'module', url: '/c/button.js', export: '' },
            },
            actions: {},
            ...overrides,
        },
    };
}

function deps(overrides = {}) {
    return {
        config: config(),
        loader: { load: vi.fn().mockResolvedValue({}) },
        http: { json: vi.fn().mockResolvedValue({}) },
        sync: { supported: false, enqueue: vi.fn() },
        navigate: vi.fn(),
        ...overrides,
    };
}

/** Reach the render function the component returns from `setup`. */
function mount(component, props = {}, ctx = {}) {
    const resolved = {
        json: { root: 'a', elements: {} },
        state: null,
        handlers: {},
        functions: null,
        only: null,
        ...props,
    };

    return component.setup(resolved, { emit: vi.fn(), slots: {}, ...ctx });
}

let disconnect = () => {};

beforeEach(() => {
    document.head.innerHTML = '';
    delete window.PwaxJson;
    stubVue();
});

afterEach(() => {
    disconnect();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('the JSON renderer loader', () => {
    it('fetches the bundle once, however many components ask for it', async () => {
        const bundle = stubBundle();
        disconnect = serveBundle(bundle);

        const json = createJson(deps());

        await Promise.all([json.load(), json.load(), json.load()]);

        expect(document.head.querySelectorAll('script').length).toBe(1);
    });

    it('carries the CSP nonce onto the script tag', async () => {
        const bundle = stubBundle();
        disconnect = serveBundle(bundle);

        const json = createJson(deps({ config: { ...config(), nonce: 'n0nce' } }));
        await json.load();

        expect(document.head.querySelector('script').nonce).toBe('n0nce');
    });

    /**
     * A load that failed because the network dropped is not a renderer that is broken
     * for the session. Remembering the rejection would leave the next navigation with a
     * blank document and no way back short of a reload.
     */
    it('forgets a failed load so the next component retries', async () => {
        disconnect = serveBundle(null, { fail: true });

        const json = createJson(deps());

        await expect(json.load()).rejects.toThrow(/failed to load/i);

        disconnect();
        disconnect = serveBundle(stubBundle());

        await expect(json.load()).resolves.toBeTruthy();
    });

    it('explains itself rather than fetching anything when the feature is off', async () => {
        const json = createJson(
            deps({
                config: {
                    nonce: null,
                    json: { enabled: false, runtime: null, components: {}, actions: {} },
                },
            })
        );

        await expect(json.load()).rejects.toThrow(/pwax\.json\.enabled/);
        expect(document.head.querySelectorAll('script').length).toBe(0);
    });
});

describe('what the component shows while and after loading', () => {
    /** The slot a page supplies for the gap before the renderer arrives. */
    it('renders the loading slot until the renderer is ready', () => {
        disconnect = serveBundle(stubBundle());

        const loading = vi.fn(() => 'waiting');
        const render = mount(createJson(deps()).PwaxJson, {}, { slots: { loading } });

        expect(render()).toBe('waiting');
        expect(loading).toHaveBeenCalled();
    });

    /**
     * A renderer that will not load is the one failure a page can present sensibly, so
     * it gets an event and a slot rather than a blank space.
     */
    it('emits error and renders the error slot when the renderer will not load', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        disconnect = serveBundle(null, { fail: true });

        const emit = vi.fn();
        const error = vi.fn(({ error: e }) => `failed: ${e.message}`);
        const render = mount(createJson(deps()).PwaxJson, {}, { emit, slots: { error } });

        await new Promise((resolve) => setTimeout(resolve, 4));

        expect(emit).toHaveBeenCalledWith('error', expect.any(Error));
        expect(String(render())).toContain('failed:');
    });

    it('renders nothing rather than a slot there is none of', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        disconnect = serveBundle(null, { fail: true });

        const render = mount(createJson(deps()).PwaxJson);

        await new Promise((resolve) => setTimeout(resolve, 4));

        expect(render()).toBeNull();
    });

    /**
     * A script that loads and publishes nothing is what a proxy interstitial or a
     * truncated response looks like. It must not resolve to `undefined`.
     */
    it('rejects when the bundle loads without publishing PwaxJson', async () => {
        const observer = new MutationObserver((records) => {
            for (const record of records) {
                for (const node of record.addedNodes) {
                    if (node.tagName === 'SCRIPT') {
                        node.dispatchEvent(new window.Event('load'));
                    }
                }
            }
        });

        observer.observe(document.head, { childList: true });
        disconnect = () => observer.disconnect();

        await expect(createJson(deps()).load()).rejects.toThrow(/did not publish PwaxJson/);
    });
});

describe('the catalog it hands the renderer', () => {
    it('passes the configured components straight through', async () => {
        const bundle = stubBundle();
        disconnect = serveBundle(bundle);

        const json = createJson(deps());
        await json.prompt();

        expect(Object.keys(bundle.calls[0].components)).toEqual(['Card', 'Button']);
        expect(bundle.calls[0].components.Card).toEqual({
            type: 'module',
            url: '/c/card.js',
            export: '',
        });
    });

    /** Both are on `window.pwax.json`, and both need the renderer loaded to answer. */
    it('answers prompt() and jsonSchema() off the full catalog', async () => {
        disconnect = serveBundle(stubBundle());

        const json = createJson(deps());

        await expect(json.prompt()).resolves.toBe('PROMPT');
        await expect(json.jsonSchema()).resolves.toEqual({ stub: true });
    });

    it('declares the built-in actions so a document may dispatch them', async () => {
        const bundle = stubBundle();
        disconnect = serveBundle(bundle);

        const json = createJson(deps());
        await json.prompt();

        expect(Object.keys(bundle.calls[0].actions).sort()).toEqual([
            'navigate',
            'reload',
            'submit',
        ]);
    });

    /**
     * The prop exists so a document nobody wrote by hand cannot reach past a named
     * subset. An empty list is the narrowest possible statement of that, and it used to
     * be read as "no restriction" — so a page narrowing the catalog from a role or a
     * feature flag was handed all of it on the one path where the list came back empty.
     */
    it('treats an empty :only as a restriction to nothing, not to everything', async () => {
        const bundle = stubBundle();
        disconnect = serveBundle(bundle);

        const json = createJson(deps());

        mount(json.PwaxJson, { only: [] });
        await new Promise((resolve) => setTimeout(resolve, 4));

        expect(Object.keys(bundle.calls[0].components)).toEqual([]);
    });

    it('keeps an empty :only apart from no :only at all', async () => {
        const bundle = stubBundle();
        disconnect = serveBundle(bundle);

        const json = createJson(deps());
        const component = json.PwaxJson;

        mount(component, { only: [] });
        mount(component);
        await new Promise((resolve) => setTimeout(resolve, 4));

        expect(bundle.createRenderer).toHaveBeenCalledTimes(2);
        expect(Object.keys(bundle.calls[0].components)).toEqual([]);
        expect(Object.keys(bundle.calls[1].components)).toEqual(['Card', 'Button']);
    });

    it('names a :only entry that matches nothing in the catalog', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        disconnect = serveBundle(stubBundle());

        mount(createJson(deps()).PwaxJson, { only: ['Card', 'Crad'] });
        await new Promise((resolve) => setTimeout(resolve, 4));

        expect(warn.mock.calls.flat().join(' ')).toContain('"Crad"');
    });

    it('builds one renderer per catalog subset and reuses it', async () => {
        const bundle = stubBundle();
        disconnect = serveBundle(bundle);

        const json = createJson(deps());
        const component = json.PwaxJson;

        mount(component, { only: ['Card'] });
        mount(component, { only: ['Card'] });
        mount(component);
        await new Promise((resolve) => setTimeout(resolve, 4));

        expect(bundle.createRenderer).toHaveBeenCalledTimes(2);
        expect(Object.keys(bundle.calls[0].components)).toEqual(['Card']);
        expect(Object.keys(bundle.calls[1].components)).toEqual(['Card', 'Button']);
    });

    /**
     * `createBundleLoader` clears its own memo on failure so the next component retries.
     * Caching the rejected renderer promise on top of it made that impossible: one
     * dropped request and every <PwaxJson> took the error slot for the rest of the
     * session, including after the connection came back.
     */
    it('does not remember a renderer that failed to build', async () => {
        disconnect = serveBundle(null, { fail: true });

        const json = createJson(deps());

        await expect(json.prompt()).rejects.toThrow(/failed to load/i);

        disconnect();
        disconnect = serveBundle(stubBundle());

        await expect(json.prompt()).resolves.toBe('PROMPT');
    });
});

describe('the document guard rails', () => {
    it('warns about an element whose content is under slots, which nothing reads', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        disconnect = serveBundle(stubBundle());

        mount(createJson(deps()).PwaxJson, {
            json: { root: 'a', elements: { a: { type: 'Card', slots: { header: ['b'] } } } },
        });

        expect(warn.mock.calls.flat().join(' ')).toMatch(/"slots".*children/s);
    });

    it('warns about a repeat on an element with no children', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        disconnect = serveBundle(stubBundle());

        mount(createJson(deps()).PwaxJson, {
            json: {
                root: 'a',
                elements: { a: { type: 'Card', repeat: { statePath: '/rows' } } },
            },
        });

        expect(warn.mock.calls.flat().join(' ')).toMatch(/repeat.*children/s);
    });

    /**
     * The warnings are for documents nobody hand-checked — generated ones — and a
     * generated document is exactly what gets swapped into `:json` after mount.
     */
    it('warns again when a new document is swapped in', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        disconnect = serveBundle(stubBundle());

        // `Vue.watch` is stubbed to a real watcher for this test only: the module-level
        // stub is a bare recorder, and what is under test is that the watch happens.
        const watchers = [];
        Vue.watch = (source, callback, options) => {
            watchers.push({ source, callback });

            if (options && options.immediate) {
                callback(source());
            }
        };

        const document = { root: 'a', elements: { a: { type: 'Card' } } };
        mount(createJson(deps()).PwaxJson, { json: document });

        expect(warn).not.toHaveBeenCalled();

        watchers[0].callback({
            root: 'a',
            elements: { a: { type: 'Card', slots: { header: ['b'] } } },
        });

        expect(warn.mock.calls.flat().join(' ')).toContain('"slots"');
    });

    /**
     * `confirm` on `removeState` is the difference between a prompt and a deletion: the
     * renderer handles the action and returns long before the confirmation branch.
     */
    it('warns about a confirm the renderer will never ask', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        disconnect = serveBundle(stubBundle());

        mount(createJson(deps()).PwaxJson, {
            json: {
                root: 'a',
                elements: {
                    a: {
                        type: 'Card',
                        on: {
                            press: {
                                action: 'removeState',
                                confirm: { title: 'Sure?', message: '…' },
                            },
                        },
                    },
                },
            },
        });

        expect(warn.mock.calls.flat().join(' ')).toContain('without');
        expect(warn.mock.calls.flat().join(' ')).toContain('removeState');
    });

    it('leaves a confirm on an action of your own alone', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        disconnect = serveBundle(stubBundle());

        mount(createJson(deps()).PwaxJson, {
            json: {
                root: 'a',
                elements: {
                    a: {
                        type: 'Card',
                        on: {
                            press: { action: 'save', confirm: { title: 'Sure?', message: '…' } },
                        },
                    },
                },
            },
        });

        expect(warn).not.toHaveBeenCalled();
    });

    it('says which setting turned it off rather than rendering silently', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const json = createJson(
            deps({
                config: {
                    nonce: null,
                    json: { enabled: false, runtime: null, components: {}, actions: {} },
                },
            })
        );

        const render = mount(json.PwaxJson);

        expect(warn.mock.calls.flat().join(' ')).toContain('pwax.json.enabled');
        expect(render()).toBeNull();
    });
});

describe('the built-in actions', () => {
    /** Reach the handlers the component hands the renderer, without a real Vue render. */
    async function handlersOf(dependencies) {
        const bundle = stubBundle();
        disconnect = serveBundle(bundle);

        const json = createJson(dependencies);
        const render = mount(json.PwaxJson);

        await new Promise((resolve) => setTimeout(resolve, 4));

        // The component swaps a `shallowRef`; the stub's `.value` is what it assigned.
        const shallow = Vue.shallowRef.mock.results[0].value;
        shallow.value = { Root: { name: 'StubRoot' }, actions: {} };

        return render().props.handlers;
    }

    it('routes a navigate through the SPA router', async () => {
        const dependencies = deps();
        const handlers = await handlersOf(dependencies);

        await handlers.navigate({ to: '/settings' });

        expect(dependencies.navigate).toHaveBeenCalledWith('/settings');
    });

    it('sends a submit through the runtime http client', async () => {
        const dependencies = deps();
        const handlers = await handlersOf(dependencies);

        await handlers.submit({ url: '/orders', data: { id: 1 } });

        expect(dependencies.http.json).toHaveBeenCalledWith(
            '/orders',
            expect.objectContaining({ method: 'POST', body: JSON.stringify({ id: 1 }) })
        );
    });

    /**
     * A form filled in on a train should send when the train leaves the tunnel, not fail.
     * The queue is the one `window.pwax.sync` already exposes.
     */
    it('queues a submit instead of sending it when the connection is gone', async () => {
        const dependencies = deps({
            sync: { supported: true, enqueue: vi.fn().mockResolvedValue(true) },
        });

        vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(false);

        const handlers = await handlersOf(dependencies);
        await handlers.submit({ url: '/orders', data: { id: 1 } });

        expect(dependencies.sync.enqueue).toHaveBeenCalled();
        expect(dependencies.http.json).not.toHaveBeenCalled();
    });

    /**
     * `enqueue()` returns false when it had nowhere to put the request. Sending it anyway
     * makes the failure the visible kind rather than a write that disappeared.
     */
    it('falls back to sending when the queue refuses the request', async () => {
        const dependencies = deps({
            sync: { supported: true, enqueue: vi.fn().mockResolvedValue(false) },
        });

        vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(false);

        const handlers = await handlersOf(dependencies);
        await handlers.submit({ url: '/orders' });

        expect(dependencies.http.json).toHaveBeenCalled();
    });

    /**
     * The only path with no coverage on either side: the PHP tests prove the config
     * resolves to a module entry, and nothing proved the runtime then imports it.
     */
    it('resolves a configured action module and hands it to the renderer', async () => {
        const bundle = stubBundle();
        disconnect = serveBundle(bundle);

        const addToCart = vi.fn();
        const dependencies = deps({
            config: {
                ...config(),
                json: {
                    ...config().json,
                    actions: { addToCart: { type: 'module', url: '/a.js' } },
                },
            },
            loader: { load: vi.fn().mockResolvedValue(addToCart) },
        });

        const json = createJson(dependencies);
        await json.prompt();

        expect(dependencies.loader.load).toHaveBeenCalledWith('/a.js', '');
        expect(Object.keys(bundle.calls[0].actions)).toContain('addToCart');
    });

    /**
     * Three sources, one name. The README promises this order, and nothing tested it.
     */
    it('lets a page handler beat a configured action, and a configured one beat a built-in', async () => {
        const bundle = stubBundle();
        disconnect = serveBundle(bundle);

        const configured = vi.fn();
        const dependencies = deps({
            config: {
                ...config(),
                json: {
                    ...config().json,
                    actions: { reload: { type: 'module', url: '/reload.js' } },
                },
            },
            loader: { load: vi.fn().mockResolvedValue(configured) },
        });

        const json = createJson(dependencies);
        const page = vi.fn();
        const render = mount(json.PwaxJson, { handlers: { navigate: page } });

        await new Promise((resolve) => setTimeout(resolve, 4));

        const shallow = Vue.shallowRef.mock.results[0].value;
        shallow.value = { Root: { name: 'StubRoot' }, actions: { reload: configured } };

        const handlers = render().props.handlers;

        // `reload` is a built-in that configuration replaced; `navigate` is a built-in
        // that this page replaced; `submit` is a built-in nobody touched.
        expect(handlers.reload).toBe(configured);
        expect(handlers.navigate).toBe(page);
        expect(typeof handlers.submit).toBe('function');
    });

    it('reloads the page', async () => {
        const reload = vi.fn();
        const original = window.location;

        // jsdom's `location` is not writable; swap it for this test only.
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { ...original, reload },
        });

        try {
            const handlers = await handlersOf(deps());
            await handlers.reload();

            expect(reload).toHaveBeenCalled();
        } finally {
            Object.defineProperty(window, 'location', { configurable: true, value: original });
        }
    });

    /**
     * `http.json()` puts this session's CSRF token on every request it makes, and the URL
     * here comes from the document — the one input in this file nobody hand-checked. The
     * browser will not let the page read the reply, but the request goes out, and a
     * server that answers the preflight is handed the header. `sync.enqueue()` already
     * refuses a cross-origin URL in these words, so without this the same document leaked
     * the token when online and was refused when offline.
     */
    it('refuses to submit to another origin', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const dependencies = deps();
        const handlers = await handlersOf(dependencies);

        await handlers.submit({ url: 'https://evil.example/collect', data: { id: 1 } });

        expect(dependencies.http.json).not.toHaveBeenCalled();
        expect(error.mock.calls.flat().join(' ')).toContain('CSRF token');
    });

    /** Checked before the queue, too — offline is not a way around it. */
    it('refuses to queue a cross-origin submit when offline', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const dependencies = deps({
            sync: { supported: true, enqueue: vi.fn().mockResolvedValue(true) },
        });

        vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(false);

        const handlers = await handlersOf(dependencies);
        await handlers.submit({ url: '//evil.example/collect' });

        expect(dependencies.sync.enqueue).not.toHaveBeenCalled();
        expect(dependencies.http.json).not.toHaveBeenCalled();
    });

    it('submits to a relative URL, which is the ordinary case', async () => {
        const dependencies = deps();
        const handlers = await handlersOf(dependencies);

        await handlers.submit({ url: 'orders/1', data: {} });

        expect(dependencies.http.json).toHaveBeenCalledWith('orders/1', expect.anything());
    });

    /**
     * The router would throw a `SecurityError` on `pushState` to another origin rather
     * than go there, but an open redirect wearing the application's own address bar is
     * not something to leave to the browser's discretion.
     */
    it('refuses to navigate to another origin', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const dependencies = deps();
        const handlers = await handlersOf(dependencies);

        await handlers.navigate({ to: 'https://evil.example/login' });

        expect(dependencies.navigate).not.toHaveBeenCalled();
        expect(error.mock.calls.flat().join(' ')).toContain('within this application');
    });

    /** Resolved, so a document may write a full URL for a page of its own application. */
    it('navigates to an absolute URL on this origin, as a path', async () => {
        const dependencies = deps();
        const handlers = await handlersOf(dependencies);

        await handlers.navigate({ to: `${window.location.origin}/orders?tab=open#top` });

        expect(dependencies.navigate).toHaveBeenCalledWith('/orders?tab=open#top');
    });

    /**
     * A relative reference cannot have changed origin, so it goes to the router as
     * written. Resolving it would be wrong, not merely different: the router reads
     * `?tab=open` against the current route, which under hash routing is not the one the
     * URL parser sees.
     */
    it('leaves a relative destination as the document wrote it', async () => {
        const dependencies = deps();
        const handlers = await handlersOf(dependencies);

        await handlers.navigate({ to: '?tab=open' });
        await handlers.navigate({ to: '#top' });

        expect(dependencies.navigate.mock.calls.flat()).toEqual(['?tab=open', '#top']);
    });

    /** A backslash pair introduces an authority too, for an http URL. */
    it('refuses a destination that reaches another origin with backslashes', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const dependencies = deps();
        const handlers = await handlersOf(dependencies);

        await handlers.navigate({ to: '\\\\evil.example/login' });

        expect(dependencies.navigate).not.toHaveBeenCalled();
    });

    it('names the missing parameter rather than posting to nowhere', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const dependencies = deps();
        const handlers = await handlersOf(dependencies);

        await handlers.submit({});

        expect(warn.mock.calls.flat().join(' ')).toContain('"url"');
        expect(dependencies.http.json).not.toHaveBeenCalled();
    });
});
