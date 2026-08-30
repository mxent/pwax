/**
 * The JSON renderer, built to `dist/pwax-json.js` and loaded on demand.
 *
 * This file is the only place in the package that imports `@json-render/vue`,
 * `@json-render/core` or `zod`. They are large — 82 kB gzipped between them, against
 * 9.7 kB for `dist/pwax.js` — and zod cannot be trimmed out, because the catalog
 * builder uses it throughout and neither package declares itself side-effect free.
 * Bundling them into the runtime would make every page in every application pay for
 * a feature most of them never use, so they are a second bundle that `src/js/json.js`
 * fetches the first time a `<PwaxJson>` is actually rendered.
 *
 * `vue` is aliased to `src/js/json/vue-global.js` by `build.js`, so the bundle shares
 * the one Vue the page already has rather than inlining a second copy.
 *
 * Nothing here knows about pwax. Components arrive as plain descriptions and a
 * `load()` function; `src/js/json.js` is what turns Pwax component URLs into those.
 */

import {
    camelize,
    Comment as CommentNode,
    defineComponent,
    h,
    markRaw,
    shallowRef,
    toHandlerKey,
} from 'vue';
import {
    ConfirmDialog,
    JSONUIProvider,
    Renderer,
    defineRegistry,
    schema,
    useActions,
    useStateStore,
} from '@json-render/vue';
import { defineCatalog } from '@json-render/core';
import { z } from 'zod';

/**
 * Turn a plain prop declaration from `config/pwax.php` into a zod schema.
 *
 * `looseObject` rather than `object`, and unconditionally: zod v4 strips keys a schema
 * does not mention, so a strict object would silently delete any prop the application
 * forgot to declare — and the short config form declares none at all. Validation here
 * is a guard rail for a generated document, not a gate on a hand-written one.
 *
 * @param {Record<string, {type?: string, values?: string[], required?: boolean}>|undefined} props
 */
function toSchema(props) {
    if (!props || typeof props !== 'object') {
        return z.looseObject({});
    }

    const shape = {};

    for (const [name, declaration] of Object.entries(props)) {
        const spec =
            declaration && typeof declaration === 'object' ? declaration : { type: declaration };

        let type;

        switch (spec.type) {
            case 'number':
                type = z.number();
                break;
            case 'boolean':
                type = z.boolean();
                break;
            case 'enum':
                // Strings only. `z.enum()` throws on anything else, and it is called while
                // the catalog is being built — so one number in one config entry would
                // take down the whole renderer rather than loosen one prop.
                // `pwax:doctor` names the entry; this keeps the page rendering meanwhile.
                type =
                    Array.isArray(spec.values) &&
                    spec.values.length > 0 &&
                    spec.values.every((value) => typeof value === 'string')
                        ? z.enum(spec.values)
                        : z.string();
                break;
            case 'array':
                type = z.array(z.unknown());
                break;
            case 'object':
                type = z.looseObject({});
                break;
            case 'string':
                type = z.string();
                break;
            default:
                // Including `any`, and including a typo. An unrecognised type is a
                // prop that is passed through rather than a prop that is rejected;
                // `pwax:doctor` is where a typo gets named.
                type = z.unknown();
                break;
        }

        shape[name] = spec.required ? type : type.optional();
    }

    return z.looseObject(shape);
}

/** Walk a dotted path on the global object without evaluating anything. */
function resolveGlobal(path) {
    return String(path)
        .split('.')
        .reduce((carry, segment) => (carry == null ? undefined : carry[segment]), globalThis);
}

/**
 * The component json-render renders for one catalog entry.
 *
 * A real component rather than the render function the library's README shows, and the
 * difference is not stylistic. A registry entry receives
 * `{props, children, emit, on, bindings, loading}` and nothing else — no element, no
 * slot map, no component instance — so a plain function has no way to discover which
 * events the document bound, and no way to reach the state store to honour a
 * `$bindState`. Both need what only a component has: a `setup()`.
 *
 * What that buys, in order:
 *
 *   - **Events, with no configuration.** The Blade component already declares `emits`.
 *     Once its options are loaded we know every event it can raise, and `on(name).bound`
 *     says which of them this document asked for. A bare function would have rendered
 *     the component perfectly and dropped every `on:` binding in silence.
 *   - **Two-way binding.** `bindings` maps a prop to a JSON Pointer, and
 *     `useStateStore()` — a composable, so it must run in `setup()` — is what writes
 *     the value back.
 *   - **Laziness.** The module is fetched the first time this entry actually renders,
 *     through the same `load()` that `@pwaxImport` uses, so styles, scoped CSS and
 *     external assets all arrive with it.
 *
 * Memoised on the entry itself by {@see catalogItemFor}, so a narrowed registry and the
 * full one share one component type instead of remounting the subtree when a page uses
 * both, and load the module once between them.
 *
 * @param {{resolve: () => Promise<object>|object, events: string[], name: string}} entry
 */
function catalogItem({ resolve, events, name }) {
    const target = shallowRef(null);
    let started = false;

    return defineComponent({
        name: `PwaxCatalog${name}`,
        props: { ctx: { type: Object, required: true } },

        setup(props) {
            const store = useStateStore();

            if (!started) {
                started = true;

                Promise.resolve()
                    .then(resolve)
                    .then((options) => {
                        // A `global` entry whose dotted path reaches nothing resolves to
                        // `undefined`, and an element that renders nothing while saying
                        // nothing is the hardest kind of catalog mistake to find. Named
                        // here the way `extensions.js` names an undefined plugin.
                        if (!options) {
                            console.warn(
                                `pwax: the "${name}" catalog component resolved to ` +
                                    'nothing. Check its entry in pwax.json.components — a ' +
                                    'dotted path is looked up on `window`, and its script ' +
                                    'must have run before the document renders.'
                            );

                            return;
                        }

                        // `markRaw` because these are component options, not data. Without
                        // it Vue walks the whole definition — template string, methods,
                        // nested component references — and makes every one of them
                        // reactive for no benefit.
                        target.value = markRaw(options);
                    })
                    .catch((error) => {
                        console.error(
                            `pwax: could not load the "${name}" catalog component.`,
                            error
                        );
                    });
            }

            return () => {
                const options = target.value;

                if (!options) {
                    // Nothing, rather than a placeholder. The component is one node in a
                    // document that is otherwise already on screen, and a spinner per node
                    // is worse than a subtree that fills in.
                    return null;
                }

                const ctx = props.ctx;
                const bound = {};

                const declared = Array.isArray(options.emits)
                    ? options.emits
                    : Object.keys(options.emits || {});

                for (const event of new Set([...declared, ...events])) {
                    // `update:x` is the write half of a two-way binding, and is wired from
                    // `bindings` below. Treating it as an ordinary event here would bind it
                    // to an action the document never asked for.
                    if (event.startsWith('update:')) {
                        continue;
                    }

                    const handle = ctx.on(event);

                    // Only what the document bound. Attaching the rest would be harmless
                    // and pointless, and it would make every component look interactive to
                    // anything inspecting the vnode.
                    if (handle && handle.bound) {
                        bound[toHandlerKey(camelize(event))] = (...args) => handle.emit(...args);
                    }
                }

                for (const [prop, path] of Object.entries(ctx.bindings || {})) {
                    bound[toHandlerKey('update:' + camelize(prop))] = (value) =>
                        store.set(path, value);
                }

                // `children` and not `slots`: the shipped Vue renderer passes the default
                // slot only — it never reads an element's `slots` key — so a component
                // written for a document has one `<slot />` and this is all of it.
                //
                // Omitted entirely when the element has no children, rather than passed
                // as a slot that renders nothing. A card or a panel that writes
                // `v-if="$slots.default"` is asking whether it was given content, and
                // handing it an empty slot answers yes.
                //
                // Emptiness is a comment node, not an absence: the renderer always passes
                // a default slot, and Vue normalises a slot that returned nothing into a
                // single placeholder comment. That is what has to be recognised here.
                return h(
                    options,
                    { ...ctx.props, ...bound },
                    isEmpty(ctx.children) ? null : { default: () => ctx.children }
                );
            };
        },
    });
}

/**
 * Did this element actually get any children?
 *
 * The renderer always passes a default slot, so `children` is never absent — an element
 * with nothing inside it arrives as a single placeholder comment, which Vue produced when
 * it normalised a slot that returned nothing.
 *
 * Vue's `Comment` is imported under another name deliberately. `Comment` is also a DOM
 * global, and bundling the shim's export beside it produced a renamed declaration and an
 * unrenamed use — so the comparison silently ran against the DOM interface and this
 * function always answered "not empty".
 *
 * @param {unknown} children
 */
function isEmpty(children) {
    if (children === undefined || children === null) {
        return true;
    }

    const nodes = Array.isArray(children) ? children : [children];

    return nodes.every((node) => node && node.type === CommentNode);
}

/**
 * Every catalog item built so far, keyed by what makes one distinct.
 *
 * Module scope rather than per-`createRenderer`, which is the whole point: a page with a
 * full `<PwaxJson>` and an `:only` one builds two registries, and without this each got
 * its own component type for the same catalog name — a second `load()` call, a second
 * `target` ref, and a remount if a component ever moved between the two.
 *
 * The name is part of the key because two catalog names may point at one component with
 * different declared `events`, and those are genuinely different items.
 *
 * @type {Map<string, object>}
 */
const items = new Map();

/**
 * The component for one catalog entry, built at most once.
 *
 * @param {string} name
 * @param {{type?: string, url?: string, export?: string, path?: string, events?: string[]}} entry
 * @param {(url: string, exportName: string) => Promise<object>} load
 */
function catalogItemFor(name, entry, load) {
    const events = Array.isArray(entry.events) ? entry.events : [];
    const key = [name, entry.type === 'global' ? entry.path : entry.url, entry.export || '']
        .concat(events)
        .join('|');

    const cached = items.get(key);

    if (cached) {
        return cached;
    }

    const item = catalogItem({
        name,
        events,
        resolve:
            entry.type === 'global'
                ? () => resolveGlobal(entry.path)
                : () => load(entry.url, entry.export || ''),
    });

    items.set(key, item);

    return item;
}

/** Test seam: forget every built catalog item. */
export function resetCatalogItems() {
    items.clear();
}

/**
 * A component for a type the catalog does not contain.
 *
 * Renders nothing, and says which name and where to add it. The library's own default
 * is a `console.warn` and an empty node, which leaves a document that is half missing
 * and a developer with no idea which half.
 */
const Unknown = defineComponent({
    name: 'PwaxUnknownComponent',
    props: { element: { type: Object, required: true } },

    setup(props) {
        console.warn(
            `pwax: the JSON document uses "${props.element.type}", which is not in the ` +
                'catalog. Add it to pwax.json.components — a document can only render ' +
                'components the catalog names.'
        );

        return () => null;
    },
});

/**
 * The confirmation dialog for an action that asked to be confirmed.
 *
 * A binding may carry `confirm`, and the renderer honours it by parking the action on
 * `pendingConfirmation` and awaiting a promise that the dialog resolves. The library
 * ships a `ConfirmationDialogManager` for that, and in 0.20.0 it cannot work: it
 * destructures `pendingConfirmation` out of the action context in `setup()`, and that
 * context exposes it as a *getter* over a ref — so the destructure captures `null` once
 * and never sees another value.
 *
 * The consequence is the worst shape a failure can take. No dialog appears, the awaited
 * promise is never settled, and the action neither runs nor fails — it hangs, silently,
 * for the life of the page.
 *
 * This reads the same context without destructuring, so the render tracks the ref and
 * updates. Nothing is forked: `useActions` and `ConfirmDialog` are both exported, and
 * the library's own manager stays in the tree rendering nothing, as it already did.
 */
const Confirmation = defineComponent({
    name: 'PwaxJsonConfirmation',

    setup() {
        const actions = useActions();

        return () => {
            const pending = actions.pendingConfirmation;

            if (!pending || !pending.action.confirm) {
                return null;
            }

            return h(ConfirmDialog, {
                confirm: pending.action.confirm,
                onConfirm: actions.confirm,
                onCancel: actions.cancel,
            });
        };
    },
});

/**
 * Build a renderer for one catalog.
 *
 * @param {{
 *   components: Record<string, object>,
 *   actions: Record<string, {description?: string}>,
 *   load: (url: string, exportName: string) => Promise<object>,
 * }} options
 */
export function createRenderer({ components = {}, actions = {}, load }) {
    const declarations = {};
    const implementations = {};

    for (const [name, entry] of Object.entries(components)) {
        declarations[name] = {
            props: toSchema(entry.props),
            slots: Array.isArray(entry.slots) ? entry.slots : ['default'],
            description: entry.description || '',
        };

        const item = catalogItemFor(name, entry, load);

        implementations[name] = (ctx) => h(item, { ctx });
    }

    const actionDeclarations = {};
    const actionImplementations = {};

    for (const [name, action] of Object.entries(actions)) {
        actionDeclarations[name] = {
            params: z.looseObject({}),
            description: (action && action.description) || '',
        };

        // The provider's `handlers` is what actually runs a dispatched action; these
        // exist so `executeAction` — the imperative path — resolves too.
        actionImplementations[name] = async () => {};
    }

    const catalog = defineCatalog(schema, {
        components: declarations,
        actions: actionDeclarations,
    });

    const { registry } = defineRegistry(catalog, {
        components: implementations,
        actions: actionImplementations,
    });

    /**
     * The mounted document.
     *
     * `handlers` is rebuilt on every render rather than memoised, because it closes over
     * the current `onAction` and the caller's current handler map, and a stale closure
     * here means a page whose second `@action` listener is never called.
     */
    const Root = defineComponent({
        name: 'PwaxJsonRoot',
        props: {
            spec: { type: Object, required: true },
            state: { type: Object, default: null },
            handlers: { type: Object, default: () => ({}) },
            // Forwarded to the provider rather than used here. Each one is a piece of
            // vocabulary the renderer offers a document and cannot supply itself:
            // `navigate` is what makes `onSuccess: {navigate}` work, `functions` is what
            // `$computed` calls, `validationFunctions` is what `validateForm` runs.
            // Left unforwarded, all three fail quietly — and the prompt the catalog
            // generates tells a model that `$computed` is available, so a document can
            // arrive using a feature that was never wired up.
            navigate: { type: Function, default: null },
            functions: { type: Object, default: null },
            validationFunctions: { type: Object, default: null },
            onAction: { type: Function, default: null },
            onStateChange: { type: Function, default: null },
        },

        setup(props) {
            return () => {
                const handlers = {};
                const names = new Set([
                    ...Object.keys(actionDeclarations),
                    ...Object.keys(props.handlers || {}),
                ]);

                for (const name of names) {
                    handlers[name] = async (params) => {
                        if (props.onAction) {
                            props.onAction(name, params);
                        }

                        const handler = (props.handlers || {})[name];

                        if (typeof handler === 'function') {
                            return handler(params);
                        }
                    };
                }

                return h(
                    JSONUIProvider,
                    {
                        registry,
                        handlers,
                        initialState: props.state || props.spec.state || {},
                        navigate: props.navigate || undefined,
                        functions: props.functions || undefined,
                        validationFunctions: props.validationFunctions || undefined,
                        onStateChange: props.onStateChange || undefined,
                    },
                    {
                        default: () => [
                            h(Renderer, { spec: props.spec, registry, fallback: Unknown }),
                            h(Confirmation),
                        ],
                    }
                );
            };
        },
    });

    return {
        Root,
        catalog,
        /** The system prompt that constrains a model to this catalog. */
        prompt: (options) => catalog.prompt(options),
        /** The JSON Schema for structured output, for a model that supports it. */
        jsonSchema: (options) => catalog.jsonSchema(options),
    };
}
