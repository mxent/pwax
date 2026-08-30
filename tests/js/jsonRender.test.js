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

/** Renders whatever it is handed, of whatever type — for the expression tests. */
const TEXT = {
    props: { value: { type: [String, Number, Boolean], default: '' } },
    template: '<i>{{ value }}</i>',
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
    '/c/text.js': TEXT,
};

/** Text and Shell together cover the expression cases: a leaf and a container. */
const VOCABULARY = {
    Text: { type: 'module', url: '/c/text.js', export: '' },
    Shell: { type: 'module', url: '/c/shell.js', export: '' },
    Field: { type: 'module', url: '/c/field.js', export: '' },
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

/**
 * Mount a document and return the container plus everything the actions touched.
 *
 * `changes` accumulates the renderer's state patches, which is how a test asserts what a
 * `setState` or an `onSuccess: {set}` actually wrote — there is no other way in, because
 * the store belongs to the provider.
 */
async function render(
    spec,
    {
        state = null,
        handlers = {},
        components = CATALOG,
        actions = { save: { description: 'Save' } },
        functions = null,
    } = {}
) {
    const loader = fakeLoader(MODULES);
    const { Root } = PwaxJson.createRenderer({
        components,
        actions,
        load: (url, exportName) => loader.load(url, exportName),
    });

    const dispatched = [];
    const changes = [];
    const navigated = [];
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = Vue.createApp({
        render: () =>
            Vue.h(Root, {
                spec,
                state,
                handlers,
                functions,
                navigate: (path) => navigated.push(path),
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
        navigated,
        app,
        html: () => host.innerHTML.replace(/<!---->/g, ''),
        /** The latest value written to a pointer, across every patch so far. */
        wrote: (path) =>
            changes
                .flat()
                .filter((change) => change.path === path)
                .map((change) => change.value)
                .pop(),
        click: (index = 0) => {
            host.querySelectorAll('button')[index].dispatchEvent(
                new window.MouseEvent('click', { bubbles: true })
            );

            return settle();
        },
        /**
         * Click the button with this label.
         *
         * By text rather than index, because the confirm dialog injects its own two
         * buttons above the document's and an index would silently start meaning
         * something else the moment a test grew a second button.
         */
        clickLabelled: (label) => {
            const button = [...host.querySelectorAll('button')].find(
                (el) => el.textContent.trim() === label
            );

            if (!button) {
                throw new Error(`no button labelled "${label}"`);
            }

            button.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

            return settle();
        },
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

/**
 * Actions, end to end through the real renderer.
 *
 * Worth pinning here rather than against a stub, because who handles what is not
 * obvious: some actions never reach Pwax at all. `setState` and its siblings are
 * intercepted by the renderer and returned early, so they need no catalog entry, no
 * handler and no configuration — and no `@action` is reported for them. Everything else
 * goes through the handler map, and is rejected before it gets there if nothing declares
 * it.
 */
describe('actions', () => {
    const PRESS = (action, extra = {}) => ({
        root: 'b',
        elements: {
            b: {
                type: 'Button',
                props: { label: 'Go' },
                on: { press: { action, ...extra } },
            },
        },
    });

    it('runs a handler and reports it through onAction', async () => {
        const ran = [];
        const view = await render(PRESS('save', { params: { id: 7 } }), {
            handlers: { save: async (params) => ran.push(params) },
        });

        await view.click();

        expect(ran).toEqual([{ id: 7 }]);
        expect(view.dispatched).toEqual([['save', { id: 7 }]]);
    });

    it('resolves a param from state before the handler sees it', async () => {
        const ran = [];
        const view = await render(PRESS('save', { params: { id: { $state: '/order/id' } } }), {
            state: { order: { id: 42 } },
            handlers: { save: async (params) => ran.push(params) },
        });

        await view.click();

        expect(ran).toEqual([{ id: 42 }]);
    });

    it('runs every binding when an event names more than one', async () => {
        const ran = [];
        const view = await render(
            {
                root: 'b',
                elements: {
                    b: {
                        type: 'Button',
                        props: { label: 'Go' },
                        on: { press: [{ action: 'save' }, { action: 'log' }] },
                    },
                },
            },
            {
                actions: { save: { description: '' }, log: { description: '' } },
                handlers: { save: async () => ran.push('save'), log: async () => ran.push('log') },
            }
        );

        await view.click();

        expect(ran).toEqual(['save', 'log']);
    });

    /**
     * The free path. No catalog entry, no handler, no PHP — a document can drive its own
     * UI, and this is the thing the docs most need to say.
     */
    it('writes state with setState, and re-renders what reads it', async () => {
        const view = await render({
            root: 'card',
            state: { open: false, who: 'Ada' },
            elements: {
                card: {
                    type: 'Card',
                    props: { title: { $template: 'Hello, ${/who}!' } },
                    children: ['toggle', 'panel'],
                },
                toggle: {
                    type: 'Button',
                    props: { label: 'Open' },
                    on: {
                        press: { action: 'setState', params: { statePath: '/open', value: true } },
                    },
                },
                panel: {
                    type: 'Button',
                    props: { label: 'Now visible' },
                    visible: { $state: '/open', eq: true },
                },
            },
        });

        expect(view.html()).not.toContain('Now visible');

        await view.click();

        expect(view.html()).toContain('Now visible');
        expect(view.wrote('/open')).toBe(true);
    });

    it('does not report a renderer state action through onAction', async () => {
        const view = await render(PRESS('setState', { params: { statePath: '/x', value: 1 } }), {
            state: { x: 0 },
        });

        await view.click();

        // It never reaches the handler map, which is where onAction is wired. Documented,
        // because "@action fires for everything" is the natural assumption and is wrong.
        expect(view.dispatched).toEqual([]);
        expect(view.wrote('/x')).toBe(1);
    });

    it('appends with pushState and removes with removeState', async () => {
        const view = await render({
            root: 'list',
            state: { rows: [{ label: 'One' }] },
            elements: {
                list: { type: 'Card', props: { title: 'Rows' }, children: ['add', 'drop'] },
                add: {
                    type: 'Button',
                    props: { label: 'Add' },
                    on: {
                        press: {
                            action: 'pushState',
                            params: { statePath: '/rows', value: { label: 'Two' } },
                        },
                    },
                },
                drop: {
                    type: 'Button',
                    props: { label: 'Drop' },
                    on: {
                        press: { action: 'removeState', params: { statePath: '/rows', index: 0 } },
                    },
                },
            },
        });

        await view.click(0);
        expect(view.wrote('/rows')).toEqual([{ label: 'One' }, { label: 'Two' }]);

        await view.click(1);
        expect(view.wrote('/rows')).toEqual([{ label: 'Two' }]);
    });

    it('writes state after the handler resolves with onSuccess', async () => {
        const view = await render(PRESS('save', { onSuccess: { set: { '/saved': true } } }), {
            handlers: { save: async () => {} },
        });

        await view.click();

        expect(view.wrote('/saved')).toBe(true);
    });

    /**
     * The one that was broken. Core guards this branch with
     * `if ("navigate" in onSuccess && navigate)`, so a provider that was never handed a
     * `navigate` skipped it in silence — and submitting a form then going somewhere is
     * the commonest thing a document does.
     */
    it('routes through navigate after the handler resolves', async () => {
        const view = await render(PRESS('save', { onSuccess: { navigate: '/thanks' } }), {
            handlers: { save: async () => {} },
        });

        await view.click();

        expect(view.navigated).toEqual(['/thanks']);
    });

    it('writes state when the handler throws, and keeps the document standing', async () => {
        const view = await render(
            PRESS('save', { onError: { set: { '/error': 'Could not save.' } } }),
            {
                handlers: {
                    save: async () => {
                        throw new Error('nope');
                    },
                },
            }
        );

        await view.click();

        expect(view.wrote('/error')).toBe('Could not save.');
        expect(view.html()).toContain('<button>Go</button>');
    });

    it('asks before running a confirmed action, and runs it when confirmed', async () => {
        const ran = [];
        const view = await render(
            PRESS('save', { confirm: { title: 'Sure?', message: 'This cannot be undone.' } }),
            { handlers: { save: async () => ran.push('save') } }
        );

        await view.click();

        expect(ran).toEqual([]);
        expect(view.host.textContent).toContain('Sure?');

        // The dialog is the library's own, rendered by the provider — nothing in Pwax
        // draws it, which is also why it arrives unstyled.
        await view.clickLabelled('Confirm');

        expect(ran).toEqual(['save']);
    });

    /**
     * Cancelling rejects the promise the renderer is awaiting, and it rethrows into an
     * `emit` that nobody awaited — so a cancel reaches the page as an unhandled
     * rejection. That is the library's, not ours, and there is no handle on that promise
     * to attach a catch to; it is caught here so the suite does not carry the noise, and
     * called out in the README, which already steers people towards confirming inside
     * their own handler.
     */
    it('does not run a confirmed action that was cancelled', async () => {
        const rejections = [];
        const capture = (error) => rejections.push(error);
        process.on('unhandledRejection', capture);

        try {
            const ran = [];
            const view = await render(
                PRESS('save', { confirm: { title: 'Sure?', message: 'This cannot be undone.' } }),
                { handlers: { save: async () => ran.push('save') } }
            );

            await view.click();
            await view.clickLabelled('Cancel');
            await settle();

            expect(ran).toEqual([]);
            expect(view.host.textContent).not.toContain('Sure?');
            expect(rejections.map(String)).toEqual(['Error: Action cancelled']);
        } finally {
            process.off('unhandledRejection', capture);
        }
    });

    it('calls a $computed function with resolved args', async () => {
        const view = await render(
            {
                root: 'card',
                state: { first: 'Ada', last: 'Lovelace' },
                elements: {
                    card: {
                        type: 'Card',
                        props: {
                            title: {
                                $computed: 'fullName',
                                args: { a: { $state: '/first' }, b: { $state: '/last' } },
                            },
                        },
                    },
                },
            },
            { functions: { fullName: ({ a, b }) => `${a} ${b}` } }
        );

        expect(view.html()).toContain('<h2>Ada Lovelace</h2>');
    });

    it('warns about an action nothing declares, and leaves the page standing', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const view = await render(PRESS('nope'));
        await view.click();

        expect(warn.mock.calls.flat().join(' ')).toContain('nope');
        expect(view.html()).toContain('<button>Go</button>');
    });
});

/**
 * The expression and element vocabulary, in full.
 *
 * json-render defines eight prop expressions and five element keys beyond `type` and
 * `props`. All of them work through this integration — none needed a change — and that
 * is worth holding still: they are the surface a generated document draws on, and a
 * regression in any one of them would show up as a document that renders almost right.
 */
describe('the document vocabulary', () => {
    const leaf = (value) => ({ root: 'a', elements: { a: { type: 'Text', props: { value } } } });

    const show = (visible, state) =>
        render(
            { root: 'a', elements: { a: { type: 'Text', props: { value: 'shown' }, visible } } },
            { state, components: VOCABULARY }
        );

    it('reads a value straight off state', async () => {
        const view = await render(leaf({ $state: '/who' }), {
            state: { who: 'Ada' },
            components: VOCABULARY,
        });

        expect(view.html()).toContain('<i>Ada</i>');
    });

    it('chooses between two values with $cond', async () => {
        const spec = leaf({ $cond: { $state: '/on', eq: true }, $then: 'yes', $else: 'no' });

        expect(
            (await render(spec, { state: { on: true }, components: VOCABULARY })).html()
        ).toContain('yes');
        expect(
            (await render(spec, { state: { on: false }, components: VOCABULARY })).html()
        ).toContain('no');
    });

    it('numbers the rows of a repeat with $index', async () => {
        const view = await render(
            {
                root: 'list',
                elements: {
                    list: {
                        type: 'Shell',
                        props: {},
                        repeat: { statePath: '/rows' },
                        children: ['row'],
                    },
                    row: { type: 'Text', props: { value: { $index: true } } },
                },
            },
            { state: { rows: ['a', 'b', 'c'] }, components: VOCABULARY }
        );

        expect(view.html()).toContain('<i>0</i>');
        expect(view.html()).toContain('<i>2</i>');
    });

    it('repeats a repeat, walking into the item with $item', async () => {
        const view = await render(
            {
                root: 'groups',
                elements: {
                    groups: {
                        type: 'Shell',
                        props: {},
                        repeat: { statePath: '/groups' },
                        children: ['items'],
                    },
                    items: {
                        type: 'Shell',
                        props: {},
                        repeat: { statePath: { $item: 'items' } },
                        children: ['leaf'],
                    },
                    leaf: { type: 'Text', props: { value: { $item: '' } } },
                },
            },
            { state: { groups: [{ items: ['a', 'b'] }] }, components: VOCABULARY }
        );

        expect(view.html()).toContain('<i>a</i>');
        expect(view.html()).toContain('<i>b</i>');
    });

    /** The repeat-scoped half of two-way binding: a field editing one row's field. */
    it('binds a field to a repeat item with $bindItem', async () => {
        const view = await render(
            {
                root: 'list',
                elements: {
                    list: {
                        type: 'Shell',
                        props: {},
                        repeat: { statePath: '/rows' },
                        children: ['edit'],
                    },
                    edit: { type: 'Field', props: { modelValue: { $bindItem: 'name' } } },
                },
            },
            { state: { rows: [{ name: 'Ada' }] }, components: VOCABULARY }
        );

        const input = view.host.querySelector('input');
        expect(input.value).toBe('Ada');

        input.value = 'Grace';
        input.dispatchEvent(new window.Event('input', { bubbles: true }));
        await settle();

        expect(view.wrote('/rows/0/name')).toBe('Grace');
    });

    it.each([
        ['gt', { $state: '/n', gt: 5 }, { n: 10 }, true],
        ['lt', { $state: '/n', lt: 5 }, { n: 10 }, false],
        ['gte', { $state: '/n', gte: 10 }, { n: 10 }, true],
        ['lte', { $state: '/n', lte: 9 }, { n: 10 }, false],
        ['neq', { $state: '/s', neq: 'x' }, { s: 'y' }, true],
        ['not', { $state: '/b', eq: true, not: true }, { b: true }, false],
        [
            'a list, which means and',
            [
                { $state: '/a', eq: 1 },
                { $state: '/b', eq: 2 },
            ],
            { a: 1, b: 2 },
            true,
        ],
        [
            '$and',
            {
                $and: [
                    { $state: '/a', eq: 1 },
                    { $state: '/b', eq: 9 },
                ],
            },
            { a: 1, b: 2 },
            false,
        ],
        [
            '$or',
            {
                $or: [
                    { $state: '/a', eq: 9 },
                    { $state: '/b', eq: 2 },
                ],
            },
            { a: 1, b: 2 },
            true,
        ],
    ])('decides visibility with %s', async (_name, condition, state, expected) => {
        const view = await show(condition, state);

        expect(view.html().includes('shown')).toBe(expected);
    });

    /**
     * `watch` is the last element key, and the only one that fires without anybody
     * touching the element it is declared on — a state change somewhere else runs it.
     */
    it('runs an action when a watched pointer changes', async () => {
        const fired = [];
        const view = await render(
            {
                root: 'box',
                elements: {
                    box: {
                        type: 'Shell',
                        props: {},
                        watch: { '/name': { action: 'save' } },
                        children: ['edit'],
                    },
                    edit: { type: 'Field', props: { modelValue: { $bindState: '/name' } } },
                },
            },
            {
                state: { name: 'Ada' },
                components: VOCABULARY,
                handlers: { save: async () => fired.push('save') },
            }
        );

        expect(fired).toEqual([]);

        const input = view.host.querySelector('input');
        input.value = 'Grace';
        input.dispatchEvent(new window.Event('input', { bubbles: true }));
        await settle();

        expect(fired).toEqual(['save']);
    });
});
