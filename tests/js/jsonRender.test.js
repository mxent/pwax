import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeLoader, loadRenderer, settle } from './helpers/jsonHarness.js';

/**
 * The JSON renderer, running for real.
 *
 * Everything here goes through the built `dist/pwax-json.js` and the vendored Vue, so
 * each test pins one behaviour of `@json-render/vue` that `src/js/json/index.js` is
 * built around. They are not documented contracts — the library's own README describes
 * a `slots` object its Vue adapter does not pass, and named slots that it never reads —
 * so an upgrade is exactly when these need to fail.
 */

let Vue;
let PwaxJson;

beforeAll(() => {
    ({ Vue, PwaxJson } = loadRenderer());
});

beforeEach(() => {
    // Catalog items are memoised for the life of the bundle, which is the behaviour one
    // of the tests below is about. Every other test wants a clean slate, or a component
    // loaded by an earlier one is already resolved when this one mounts.
    PwaxJson.resetCatalogItems();
});

const CARD = {
    props: { title: String },
    template:
        '<section class="card"><h2>{{ title }}</h2><div class="body"><slot /></div></section>',
};

const BUTTON = {
    props: { label: String },
    emits: ['press'],
    template: '<button @click="$emit(\'press\')">{{ label }}</button>',
};

const FIELD = {
    props: { label: String, modelValue: String },
    emits: ['update:modelValue'],
    template:
        '<label>{{ label }}<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></label>',
};

/** Renders its wrapper only when it was actually given content. */
const SHELL = {
    template: '<div class="shell"><main v-if="$slots.default"><slot /></main></div>',
};

const MODULES = {
    '/c/card.js': CARD,
    '/c/button.js': BUTTON,
    '/c/field.js': FIELD,
    '/c/shell.js': SHELL,
};

const CATALOG = {
    Card: { type: 'module', url: '/c/card.js', export: '' },
    Button: {
        type: 'module',
        url: '/c/button.js',
        export: '',
        description: 'A button',
        props: { label: { type: 'string', required: true } },
    },
    Field: { type: 'module', url: '/c/field.js', export: '' },
};

/** Mount a document and return the container plus what the actions saw. */
async function render(spec, { state = null, handlers = {}, components = CATALOG } = {}) {
    const loader = fakeLoader(MODULES);
    const { Root } = PwaxJson.createRenderer({
        components,
        actions: { save: { description: 'Save' }, navigate: { description: 'Go' } },
        load: (url, exportName) => loader.load(url, exportName),
    });

    const dispatched = [];
    const changes = [];
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = Vue.createApp({
        render: () =>
            Vue.h(Root, {
                spec,
                state,
                handlers,
                onAction: (name, params) => dispatched.push([name, params]),
                onStateChange: (patch) => changes.push(patch),
            }),
    });

    app.config.warnHandler = () => {};
    app.mount(host);
    await settle();

    return {
        host,
        loader,
        dispatched,
        changes,
        app,
        html: () => host.innerHTML.replace(/<!---->/g, ''),
    };
}

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('the JSON renderer', () => {
    it('renders a catalog component and resolves a $template against state', async () => {
        const view = await render({
            root: 'a',
            state: { user: { name: 'Ada' } },
            elements: {
                a: { type: 'Card', props: { title: { $template: 'Hello, ${/user/name}!' } } },
            },
        });

        expect(view.html()).toContain('<h2>Hello, Ada!</h2>');
    });

    /**
     * The library's Vue adapter passes an element's children as the default slot and
     * nothing else — it never reads `element.slots`. A catalog component therefore has
     * one `<slot />`, which is why `config/pwax.php` says so.
     */
    it('passes children through the default slot', async () => {
        const view = await render({
            root: 'a',
            elements: {
                a: { type: 'Card', props: { title: 'Box' }, children: ['b', 'c'] },
                b: { type: 'Button', props: { label: 'One' } },
                c: { type: 'Button', props: { label: 'Two' } },
            },
        });

        expect(view.html()).toContain(
            '<div class="body"><button>One</button><button>Two</button></div>'
        );
    });

    /**
     * The reason a catalog entry is a component and not the arrow function the library's
     * README shows. A registry entry is handed `{props, children, emit, on, bindings}`
     * and no list of event names, so the bridge reads them off the loaded component's
     * own `emits`. Get this wrong and every document renders perfectly and does nothing.
     */
    it('wires an event the component declares in emits to the action it is bound to', async () => {
        const view = await render({
            root: 'b',
            elements: {
                b: {
                    type: 'Button',
                    props: { label: 'Save' },
                    on: { press: { action: 'save', params: { id: 7 } } },
                },
            },
        });

        view.host
            .querySelector('button')
            .dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
        await settle();

        expect(view.dispatched).toEqual([['save', { id: 7 }]]);
    });

    it('leaves an event the document did not bind unwired', async () => {
        const view = await render({
            root: 'b',
            elements: { b: { type: 'Button', props: { label: 'Quiet' } } },
        });

        view.host
            .querySelector('button')
            .dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
        await settle();

        expect(view.dispatched).toEqual([]);
    });

    /**
     * `$bindState` is the write half of the state store, and the only way to reach it is
     * a composable — which is the second reason a catalog entry needs a real `setup()`.
     * The assertion is the round trip: typing updates the pointer, and the `$template`
     * reading that pointer re-renders.
     */
    it('writes a $bindState prop back to state, and re-resolves what reads it', async () => {
        const view = await render({
            root: 'a',
            state: { user: { name: 'Ada' } },
            elements: {
                a: {
                    type: 'Card',
                    props: { title: { $template: 'Hello, ${/user/name}!' } },
                    children: ['f'],
                },
                f: {
                    type: 'Field',
                    props: { label: 'Name', modelValue: { $bindState: '/user/name' } },
                },
            },
        });

        const input = view.host.querySelector('input');
        input.value = 'Grace';
        input.dispatchEvent(new window.Event('input', { bubbles: true }));
        await settle();

        expect(view.html()).toContain('<h2>Hello, Grace!</h2>');
        expect(view.changes.flat()).toEqual([{ path: '/user/name', value: 'Grace' }]);
    });

    /** `repeat` repeats an element's children, so it belongs on the container. */
    it('repeats a container over an array in state', async () => {
        const view = await render({
            root: 'a',
            state: { people: [{ name: 'Ada' }, { name: 'Grace' }] },
            elements: {
                a: {
                    type: 'Card',
                    props: { title: 'People' },
                    repeat: { statePath: '/people', key: 'name' },
                    children: ['row'],
                },
                row: { type: 'Button', props: { label: { $item: 'name' } } },
            },
        });

        expect(view.html()).toContain('<button>Ada</button>');
        expect(view.html()).toContain('<button>Grace</button>');
    });

    it('hides an element whose visible condition is false, and shows it when it is true', async () => {
        const spec = (open) => ({
            root: 'a',
            state: { open },
            elements: {
                a: { type: 'Card', props: { title: 'Box' }, children: ['b'] },
                b: {
                    type: 'Button',
                    props: { label: 'Maybe' },
                    visible: { $state: '/open', eq: true },
                },
            },
        });

        expect((await render(spec(false))).html()).not.toContain('Maybe');
        expect((await render(spec(true))).html()).toContain('Maybe');
    });

    /**
     * A generated document naming a component nobody added is the failure this catalog
     * exists to contain, so it must be loud and it must not take the page with it.
     */
    it('names an unknown component and renders the rest of the document', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const view = await render({
            root: 'a',
            elements: {
                a: { type: 'Card', props: { title: 'Box' }, children: ['ghost'] },
                ghost: { type: 'Nope', props: {} },
            },
        });

        expect(view.html()).toContain('<h2>Box</h2>');
        expect(warn.mock.calls.flat().join(' ')).toContain('Nope');
        expect(warn.mock.calls.flat().join(' ')).toContain('pwax.json.components');
    });

    /** Only what the document actually uses is fetched. */
    it('loads a catalog component the first time it renders, and no others', async () => {
        const view = await render({
            root: 'b',
            elements: { b: { type: 'Button', props: { label: 'Only me' } } },
        });

        expect(view.loader.calls).toEqual(['/c/button.js']);
    });

    /**
     * A panel that asks whether it was given content — `v-if="$slots.default"` — is
     * ordinary Vue. Handing it a slot that renders nothing answers yes, and it draws its
     * wrapper around an empty hole.
     */
    it('gives a childless element no default slot at all', async () => {
        const view = await render(
            { root: 'a', elements: { a: { type: 'Shell', props: {} } } },
            { components: { Shell: { type: 'module', url: '/c/shell.js', export: '' } } }
        );

        expect(view.html()).toBe('<div class="shell"></div>');
    });

    it('gives an element with children the slot it was passed', async () => {
        const view = await render(
            {
                root: 'a',
                elements: {
                    a: { type: 'Shell', props: {}, children: ['b'] },
                    b: { type: 'Button', props: { label: 'Inside' } },
                },
            },
            {
                components: {
                    Shell: { type: 'module', url: '/c/shell.js', export: '' },
                    Button: { type: 'module', url: '/c/button.js', export: '' },
                },
            }
        );

        expect(view.html()).toContain('<main><button>Inside</button></main>');
    });

    /**
     * A page with a full <PwaxJson> and an `:only` one builds two registries. Without a
     * shared memo each got its own component type for the same catalog name — a second
     * module load, and a remount if a component ever moved between the two.
     */
    it('loads a shared component once across two registries', async () => {
        const loader = fakeLoader(MODULES);
        const entry = { type: 'module', url: '/c/card.js', export: '' };
        const load = (url, exportName) => loader.load(url, exportName);

        const mount = (renderer) => {
            const host = window.document.createElement('div');
            window.document.body.appendChild(host);

            const app = Vue.createApp({
                render: () =>
                    Vue.h(renderer.Root, {
                        spec: { root: 'a', elements: { a: { type: 'Card', props: {} } } },
                    }),
            });

            app.config.warnHandler = () => {};
            app.mount(host);
        };

        mount(PwaxJson.createRenderer({ components: { Card: entry }, actions: {}, load }));
        mount(
            PwaxJson.createRenderer({
                components: {
                    Card: entry,
                    Button: { type: 'module', url: '/c/button.js', export: '' },
                },
                actions: {},
                load,
            })
        );

        await settle();

        expect(loader.calls).toEqual(['/c/card.js']);
    });

    /**
     * A `global` entry whose dotted path reaches nothing resolves to undefined. Rendering
     * nothing while saying nothing is the hardest kind of catalog mistake to find.
     */
    it('names a window component whose path resolves to nothing', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        await render(
            { root: 'a', elements: { a: { type: 'Ghost', props: {} } } },
            { components: { Ghost: { type: 'global', path: 'Nope.Missing' } } }
        );

        expect(warn.mock.calls.flat().join(' ')).toContain('"Ghost"');
        expect(warn.mock.calls.flat().join(' ')).toContain('pwax.json.components');
    });

    /**
     * The catalog schema is `looseObject`, deliberately. zod v4 strips keys a strict
     * object does not name, so a declared component would silently lose every prop the
     * application forgot to list — and the short catalog form lists none at all. Here
     * `extra` survives the schema and lands on the element as a fallthrough attribute,
     * which is the visible proof it was not dropped on the way in.
     */
    it('keeps a prop the catalog never declared', async () => {
        const view = await render({
            root: 'b',
            elements: { b: { type: 'Button', props: { label: 'Go', extra: 'kept' } } },
        });

        expect(view.html()).toContain('<button extra="kept">Go</button>');
    });
});
