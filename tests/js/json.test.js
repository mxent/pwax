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

    it('names the missing parameter rather than posting to nowhere', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const dependencies = deps();
        const handlers = await handlersOf(dependencies);

        await handlers.submit({});

        expect(warn.mock.calls.flat().join(' ')).toContain('"url"');
        expect(dependencies.http.json).not.toHaveBeenCalled();
    });
});
