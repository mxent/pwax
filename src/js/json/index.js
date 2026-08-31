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
    nextTick,
    shallowRef,
    toHandlerKey,
    watch,
} from 'vue';
import {
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
 * Prop names that write markup rather than data, keyed lowercase.
 *
 * Vue sets each of these as a DOM property on whatever element a component has at its
 * root — `shouldSetAsProp` answers `key in el` — so a string arriving here is parsed as
 * HTML, not shown as text.
 */
const MARKUP_PROPS = new Set(['innerhtml', 'outerhtml', 'textcontent', 'innertext', 'srcdoc']);

/**
 * Would setting this prop hand the document a script or a markup sink?
 *
 * Two families, and both are reached through props a component never declared: Vue
 * passes those through to the root element as fallthrough attributes, where they stop
 * being data and start being DOM.
 *
 *   - **`on*`.** `onclick` is not one of Vue's event props — `isOn` wants `on` followed
 *     by a non-lowercase character — so it takes the attribute path and comes out as
 *     `setAttribute('onclick', …)`, which is an inline handler and runs. `onClick` *is*
 *     an event prop, and a string is not a function, so that one is inert; the check
 *     does not try to tell them apart, because the distinction is a Vue implementation
 *     detail and the document has a proper channel for events either way.
 *   - **The markup sinks above.** `innerHTML` is the plain case, and it executes:
 *     `<img src=x onerror=…>` fires on insertion.
 *
 * Vue's own prefixes have to be undone first. `.name` forces the DOM-property path and
 * `^name` forces the attribute path, so `^onClick` reaches `setAttribute('onClick', …)`
 * — which an HTML element lowercases into a live `onclick` — and `.innerHTML` reaches
 * the property directly. A check on the written key would miss both.
 *
 * @param {string} key
 */
function unsafeProp(key) {
    const name = key[0] === '.' || key[0] === '^' ? key.slice(1) : key;
    const lower = name.toLowerCase();

    // Everything beginning with `on`, not a list of known event names. A list is a thing
    // to keep up to date, and the cost of being blunt is a dropped `online` prop with a
    // console line saying so — against a missed handler attribute nobody finds.
    return lower.startsWith('on') || MARKUP_PROPS.has(lower);
}

/**
 * The scheme the URL parser would read off this string, lowercased.
 *
 * Not the characters as written. The parser strips C0 controls and spaces from the
 * front of a URL and removes tab, newline and carriage return from *anywhere* in it, so
 * `java&#9;scri&#10;pt:alert(1)` is a `javascript:` URL and a check against the literal
 * prefix waves it through. Padding is unbounded — three hundred leading tabs are still a
 * valid URL — which is why this walks the string rather than normalising a fixed slice:
 * a slice long enough to be safe does not exist.
 *
 * Sixteen characters is enough to recognise every scheme below, and is where the walk
 * stops. For an ordinary prop that is sixteen iterations and no work at all.
 *
 * @param {string} value
 */
function urlScheme(value) {
    let head = '';

    for (let i = 0; i < value.length && head.length < 16; i++) {
        const character = value[i];

        if (character === '\t' || character === '\n' || character === '\r') {
            continue;
        }

        if (head === '' && character <= ' ') {
            continue;
        }

        head += character;
    }

    return head.toLowerCase();
}

/**
 * Is this value a URL that is really a script?
 *
 * The prop-name check above stops a document putting a handler on an element. It does
 * nothing about a value, and a value reaches a sink whenever a component renders one as
 * a URL — `<a :href>`, `<iframe :src>`, `<form :action>`, all of them perfectly ordinary
 * things for a catalog component to do. A `javascript:` href runs on click with the
 * page's own origin, and it does not even have to look like an attack: the expression's
 * result replaces the document, so the visible symptom is a blank page.
 *
 * `data:text/html` is here for an `<iframe :src>`: browsers already refuse a top-level
 * navigation to one, and a frame is where it still runs — with its own origin, but with
 * the page in reach through `window.parent` if anything opts in.
 *
 * @param {string} value
 */
function isScriptUrl(value) {
    const scheme = urlScheme(value);

    return (
        scheme.startsWith('javascript:') ||
        scheme.startsWith('vbscript:') ||
        scheme.startsWith('data:text/html')
    );
}

/**
 * Does this prop carry a script URL anywhere inside it?
 *
 * Every level, not just the top one. A prop is very often a list — a menu's `items`, a
 * table's `columns` — and the component renders each entry's `href` exactly as it would
 * render a `href` prop. Checked only at the top, the rule was a boundary an author
 * stepped over by nesting one level, which was confirmed: a `javascript:` URL inside an
 * `items` array ran on click.
 *
 * Unbounded, and safely so. A document is parsed JSON, which cannot contain a cycle,
 * and `@json-render/vue` has already resolved these props with a walk of its own by the
 * time they arrive — a cyclic object handed to `:json` by application code overflows the
 * stack in the resolver and never reaches a render. So there is no cycle guard here, and
 * no depth limit either: a limit is either a bypass or a legitimate prop dropped for
 * being deep.
 *
 * `v-html` is deliberately not in scope. A component that renders a prop as markup is
 * accepting markup on purpose, which is the component author's decision to make and to
 * validate — the same decision they make about a controller's data today.
 *
 * @param {unknown} value
 */
function unsafeValue(value) {
    if (typeof value === 'string') {
        return isScriptUrl(value);
    }

    if (value === null || typeof value !== 'object') {
        return false;
    }

    return (Array.isArray(value) ? value : Object.values(value)).some(unsafeValue);
}

/**
 * Names already reported, so a dropped prop is one console line and not one per render.
 *
 * @type {Set<string>}
 */
const reported = new Set();

/**
 * Report a dropped prop, the first time it is dropped.
 *
 * @param {string} seen
 * @param {string} message
 */
function warnOnce(seen, message) {
    if (reported.has(seen)) {
        return;
    }

    reported.add(seen);

    console.warn(message);
}

/**
 * The document's props, minus anything that would make the document itself executable.
 *
 * This is the line the catalog draws. A document names components and passes them data;
 * what those components then render is the application's code, reviewed and served from
 * its own origin. Without this, any prop name at all reaches the root element, and a
 * document — which may be generated, stored, or arrive from somewhere nobody audits —
 * can put `onclick` or `innerHTML` on any component in the catalog and run script with
 * it. Dropped rather than rejected: one bad prop should cost that prop, not the page.
 *
 * @param {Record<string, unknown>} props
 * @param {string} name
 */
function safeProps(props, name) {
    const safe = {};

    for (const key of Object.keys(props || {})) {
        if (unsafeProp(key)) {
            warnOnce(
                `${name}.${key}`,
                `pwax: the JSON document sets "${key}" on a "${name}" element. It was ` +
                    'dropped — a document cannot set event handlers or markup on a ' +
                    'component. Bind an event under the element\'s "on" key, and pass ' +
                    'content as a prop the component renders as text.'
            );

            continue;
        }

        if (unsafeValue(props[key])) {
            warnOnce(
                `${name}.${key}:value`,
                `pwax: the JSON document passes a script URL to "${key}" on a "${name}" ` +
                    'element. It was dropped — a "javascript:" URL runs with this ' +
                    "application's origin. Use a path, or an action under the element's " +
                    '"on" key.'
            );

            continue;
        }

        safe[key] = props[key];
    }

    return safe;
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

                for (const event of eventsOf(options, events)) {
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
                    { ...safeProps(ctx.props, name), ...bound },
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
 * The events a document may bind on a component.
 *
 * `emits` is the contract — whatever a component declares there is what a document can
 * bind with `on`, which is why configuration never repeats it. A catalog entry may still
 * name `events` explicitly, and has to for a `global`: a component reached by dotted path
 * arrives as options nobody compiled, so there is nothing to read.
 *
 * `update:x` is dropped. It is the write half of a two-way binding, wired from
 * `bindings`, and binding it to an action the document never asked for is not the same
 * thing as an event.
 *
 * @param {object|null|undefined} options
 * @param {string[]} extra
 * @returns {string[]}
 */
function eventsOf(options, extra) {
    const emits = options && options.emits;
    const declared = Array.isArray(emits) ? emits : Object.keys(emits || {});

    return [...new Set([...declared, ...extra])].filter((name) => !name.startsWith('update:'));
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

/** Test seam: forget every built catalog item, and every prop already warned about. */
export function resetCatalogItems() {
    items.clear();
    reported.clear();
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
 * Styles for the dialog below.
 *
 * Inline rather than a stylesheet, because a stylesheet injected from a bundle needs the
 * CSP nonce and this component has no way to reach it. The trade is that an application
 * overriding these needs `!important` — the class names are on every element for exactly
 * that — which is why the README says the dialog will not match your design and suggests
 * confirming in your own handler when the look matters.
 *
 * The colours are the CSS system colours, which resolve against the `color-scheme` in
 * effect — so the dialog follows the application, not the operating system. Measured:
 * with no `color-scheme` declared, `Canvas` is white whatever the visitor prefers, and in
 * an application that declares `light dark` it is white or `#121212` as the visitor
 * prefers. That is the behaviour to want, and it is the reason nothing here declares a
 * `color-scheme` of its own: forcing `light dark` on this subtree would put a dark dialog
 * over a light-only application whenever the visitor's system was set to dark. The
 * library's own dialog hardcodes `white`, so it is a white card on a dark page instead.
 */
const DIALOG_STYLES = {
    backdrop: {
        position: 'fixed',
        inset: '0',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '1rem',
        background: 'rgba(0, 0, 0, .5)',
        zIndex: '50',
    },
    panel: {
        background: 'Canvas',
        color: 'CanvasText',
        border: '1px solid ButtonBorder',
        borderRadius: '8px',
        padding: '1.5rem',
        maxWidth: '25rem',
        width: '100%',
        boxShadow: '0 20px 25px -5px rgba(0, 0, 0, .25)',
    },
    title: { margin: '0 0 .5rem', fontSize: '1.125rem', fontWeight: '600' },
    message: { margin: '0 0 1.5rem', color: 'GrayText' },
    actions: { display: 'flex', gap: '.75rem', justifyContent: 'flex-end' },
    cancel: {
        padding: '.5rem 1rem',
        borderRadius: '6px',
        border: '1px solid ButtonBorder',
        background: 'ButtonFace',
        color: 'ButtonText',
        cursor: 'pointer',
    },
    confirm: {
        padding: '.5rem 1rem',
        borderRadius: '6px',
        border: '1px solid transparent',
        color: '#fff',
        cursor: 'pointer',
    },
};

/** Ids, so `aria-labelledby` points somewhere unique when two documents are on a page. */
let dialogCount = 0;

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
 * for the life of the page. This reads the same context without destructuring, so the
 * render tracks the ref and updates. The library's own manager stays in the tree
 * rendering nothing, as it already did; nothing is forked.
 *
 * The dialog itself is ours rather than the library's `ConfirmDialog`, because that one
 * is a `div` with two buttons: no `role`, no `aria-modal`, no label, focus left wherever
 * it was, no focus trap and no Escape. It is the only interface this package renders in
 * the whole feature — everything else on the page is the application's own components —
 * and a document can ask for it, so an application cannot decline it. So it is:
 *
 *   - a labelled `role="dialog"` with `aria-modal`, which is what a screen reader needs
 *     to announce it as a dialog rather than read past it as more page;
 *   - focused on open, at Cancel — the safe half of a destructive question — and
 *     returned afterwards to whatever the visitor was on, which for a `press` binding is
 *     the button they came from;
 *   - trapped, so Tab cycles the two buttons instead of walking into the page behind;
 *   - dismissible with Escape, which for a confirmation means cancel.
 */
const Confirmation = defineComponent({
    name: 'PwaxJsonConfirmation',

    setup() {
        const actions = useActions();
        const id = `pwax-confirm-${++dialogCount}`;

        /** Where focus was before the dialog took it. */
        let restore = null;
        /** The panel, for the trap: what is inside it is what Tab may reach. */
        let panel = null;

        const buttons = () => (panel ? [...panel.querySelectorAll('button')] : []);

        watch(
            () => Boolean(actions.pendingConfirmation),
            (open) => {
                if (open) {
                    restore = document.activeElement;

                    // After the render that puts the panel on screen; there is nothing to
                    // focus until then.
                    nextTick(() => {
                        const [first] = buttons();

                        if (first) {
                            first.focus();
                        }
                    });

                    return;
                }

                // Both branches of the dialog end here — confirm and cancel — so this is
                // the one place that has to put focus back.
                if (restore && typeof restore.focus === 'function') {
                    restore.focus();
                }

                restore = null;
                panel = null;
            }
        );

        function onKeydown(event) {
            if (event.key === 'Escape') {
                // Stopped, so a page listening for Escape does not also close something
                // of its own behind a dialog the visitor was answering.
                event.stopPropagation();
                actions.cancel();

                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const items = buttons();

            if (items.length === 0) {
                return;
            }

            const first = items[0];
            const last = items[items.length - 1];
            const active = document.activeElement;
            const outside = !panel || !panel.contains(active);

            if (event.shiftKey && (outside || active === first)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (outside || active === last)) {
                event.preventDefault();
                first.focus();
            }
        }

        return () => {
            const pending = actions.pendingConfirmation;

            if (!pending || !pending.action.confirm) {
                return null;
            }

            const confirm = pending.action.confirm;
            const danger = confirm.variant === 'danger';

            return h('div', { class: 'pwax-confirm', style: DIALOG_STYLES.backdrop, onKeydown }, [
                h(
                    'div',
                    {
                        class: 'pwax-confirm__panel',
                        style: DIALOG_STYLES.panel,
                        role: 'dialog',
                        'aria-modal': 'true',
                        'aria-labelledby': `${id}-title`,
                        'aria-describedby': confirm.message ? `${id}-message` : undefined,
                        ref: (el) => {
                            panel = el;
                        },
                    },
                    [
                        h(
                            'h2',
                            {
                                class: 'pwax-confirm__title',
                                style: DIALOG_STYLES.title,
                                id: `${id}-title`,
                            },
                            confirm.title ?? 'Are you sure?'
                        ),
                        confirm.message
                            ? h(
                                  'p',
                                  {
                                      class: 'pwax-confirm__message',
                                      style: DIALOG_STYLES.message,
                                      id: `${id}-message`,
                                  },
                                  confirm.message
                              )
                            : null,
                        h('div', { class: 'pwax-confirm__actions', style: DIALOG_STYLES.actions }, [
                            h(
                                'button',
                                {
                                    class: 'pwax-confirm__cancel',
                                    type: 'button',
                                    style: DIALOG_STYLES.cancel,
                                    onClick: actions.cancel,
                                },
                                confirm.cancelLabel ?? 'Cancel'
                            ),
                            h(
                                'button',
                                {
                                    class: danger
                                        ? 'pwax-confirm__confirm pwax-confirm__confirm--danger'
                                        : 'pwax-confirm__confirm',
                                    type: 'button',
                                    style: {
                                        ...DIALOG_STYLES.confirm,
                                        background: danger ? '#b91c1c' : '#1d4ed8',
                                    },
                                    onClick: actions.confirm,
                                },
                                confirm.confirmLabel ?? 'Confirm'
                            ),
                        ]),
                    ]
                ),
            ]);
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
            events: Array.isArray(entry.events) ? entry.events : [],
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

    /**
     * The catalog again, with every component's own events filled in.
     *
     * `prompt()` tells a model that each key of `on` is "an event name (from the
     * component's supported events)" and then has to list them, or the model guesses —
     * and an event name no component emits binds to nothing and reports nothing. The
     * names live on the loaded component's `emits`, which is the whole reason a catalog
     * entry does not repeat them, so they are not known until the component is fetched.
     *
     * Which is why this is separate from the catalog the registry uses. Rendering stays
     * lazy: a page fetches the components its document actually names. Describing the
     * catalog fetches all of them, once, on a call that is already asynchronous and is
     * made when generating a document rather than when showing one.
     *
     * A component that fails to load still gets its entry, with whatever `events` the
     * configuration named. A prompt missing one component's events is worth having; no
     * prompt at all is not.
     *
     * @type {Promise<any>|null}
     */
    let described = null;

    function describedCatalog() {
        if (described) {
            return described;
        }

        described = Promise.all(
            Object.entries(components).map(async ([name, entry]) => {
                const extra = Array.isArray(entry.events) ? entry.events : [];

                try {
                    const options =
                        entry.type === 'global'
                            ? resolveGlobal(entry.path)
                            : await load(entry.url, entry.export || '');

                    return [name, eventsOf(options, extra)];
                } catch {
                    return [name, extra];
                }
            })
        ).then((resolved) => {
            const described = {};

            for (const [name, events] of resolved) {
                described[name] = { ...declarations[name], events };
            }

            return defineCatalog(schema, {
                components: described,
                actions: actionDeclarations,
            });
        });

        return described;
    }

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
            // Forwarded to the provider rather than used here. Both are vocabulary the
            // renderer offers a document and cannot supply itself: `navigate` is what
            // makes `onSuccess: {navigate}` work, and `functions` is what `$computed`
            // calls. Left unforwarded they fail quietly — and the prompt the catalog
            // generates tells a model that `$computed` is available, so a document can
            // arrive using a feature that was never wired up.
            //
            // `validationFunctions` is deliberately absent. It only ever reaches a field
            // registered with `useFieldValidation`, and that is a composable a component
            // calls inside its own `setup()` — which a catalog component, loaded as a
            // separate module from the server, has no way to reach. Forwarding it would
            // be a prop that can never do anything.
            navigate: { type: Function, default: null },
            functions: { type: Object, default: null },
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
        prompt: (options) => describedCatalog().then((described) => described.prompt(options)),
        /** The JSON Schema for structured output, for a model that supports it. */
        jsonSchema: (options) =>
            describedCatalog().then((described) => described.jsonSchema(options)),
    };
}
