#!/usr/bin/env node
/**
 * Server-side render a Pwax component to an HTML string.
 *
 * The PHP side ({@see \Mxent\Pwax\Pwa\Ssr\Prerenderer}) spawns this script once per
 * prerendered response (cached on the component hash + data digest), feeds it a JSON
 * payload on stdin, and reads `{ok, html, serializedState, errors}` on stdout. Nothing
 * else goes to stdout; diagnostics go to stderr, so a stray console.log cannot corrupt
 * the result the PHP side parses.
 *
 * The payload is the same component payload the browser receives — `Pwax::payload()`, so
 * `template`, `script`, `style`, `scope`, and any precompiled render function riding inside
 * that script — plus the controller `data` the route passed to `pwaxRender()`. The script is evaluated as an ES module via a `data:` URL, so the
 * author's `export default { … }` resolves exactly as it does in the browser. That script
 * may reference the global `Vue`: when `php artisan pwax:compile` has run, the payload
 * carries a precompiled render function generated with `runtimeGlobalName: 'Vue'`, and it
 * dereferences that global as the module evaluates. Node's module scope does not provide
 * one, so the bridge publishes it (see `globalThis.Vue` below) exactly as the browser does.
 *
 * `vue`, `@vue/server-renderer` and `@vue/compiler-dom` are optional peer dependencies,
 * just as `@vue/compiler-dom` is for `bin/compile-templates.mjs`. An application that has
 * not opted into SSR never installs them; the doctor reports the missing dependency with
 * the install command rather than letting the request fail.
 *
 *   npm install --save-dev vue@<v> @vue/server-renderer@<v> @vue/compiler-dom@<v>
 *
 * All three, and `vue` is not optional among them: the server renderer renders a Vue
 * application, so it needs the runtime that Pwax otherwise vendors as a browser global.
 * Leaving it out is the one mistake this script cannot survive, and it used to fail as an
 * unhandled module-resolution error on the very first line that mattered — no JSON on
 * stdout, so the PHP side reported only "Node exited with 1" and fell back to the SPA on
 * every request, forever.
 *
 * The version guard below ensures the runtime, the server renderer and the compiler all
 * match the Vue build Pwax ships, so the HTML this script produces hydrates cleanly in the
 * browser.
 */

import { createRequire } from 'node:module';
import { pageTemplate } from '../src/js/pageTemplate.mjs';

const require = createRequire(import.meta.url);

/*
 * Vue's development build, deliberately, whatever the environment says.
 *
 * Vue strips `warn()` from its production build, and two of the things this bridge must not
 * do quietly are reported only as warnings: an unresolved component renders as a literal
 * `<MysteryThing></MysteryThing>` element, and an unresolved directive is silently dropped.
 * Both produce markup that is merely *different* from what the browser will build, which is
 * worse than failing — a crawler indexes it and the visitor's browser throws it away.
 *
 * The rendered HTML is byte-identical between the two builds; only the diagnostics differ.
 * So a server with NODE_ENV=production still gets production markup, and keeps the checks
 * that decide whether that markup can be trusted.
 *
 * Set here rather than beside the `import('vue')` below: `@vue/server-renderer` pulls the
 * runtime in as it loads, and the dev/prod branch is resolved the first time either is
 * required. A few lines later is already too late.
 */
process.env.NODE_ENV = 'development';

const write = (payload) => process.stdout.write(JSON.stringify(payload));

const fail = (message, extra = {}) => {
    write({ ok: false, message, ...extra });
    process.exit(0);
};

const read = () =>
    new Promise((resolve, reject) => {
        let raw = '';

        process.stdin.setEncoding('utf8');
        process.stdin.on('data', (chunk) => (raw += chunk));
        process.stdin.on('end', () => resolve(raw));
        process.stdin.on('error', reject);
    });

let input;

try {
    input = JSON.parse((await read()) || '{}');
} catch (error) {
    fail(`Could not read the SSR payload from stdin: ${error.message}`);
}

const wanted = typeof input.version === 'string' ? input.version : '';
const pin = wanted || 'latest';
const install = `\`npm install --save-dev vue@${pin} @vue/server-renderer@${pin} @vue/compiler-dom@${pin}\``;

let compiler;

try {
    compiler = await import('@vue/compiler-dom');
} catch {
    fail(
        `The optional peer dependency @vue/compiler-dom is not installed. Run ${install} ` +
            'in your application.'
    );
}

let renderer;

try {
    renderer = await import('@vue/server-renderer');
} catch {
    fail(
        `The optional peer dependency @vue/server-renderer is not installed. Run ${install} ` +
            'in your application, or set pwax.ssr.enabled to false.'
    );
}

/*
 * The server renderer, the compiler and the shipped Vue runtime must all be the same
 * version. A mismatch emits HTML the runtime cannot hydrate, and the failure surfaces at
 * hydration time in the browser with an error that names none of them. Refusing here costs
 * one clear message instead. Same guard as `bin/compile-templates.mjs`.
 */
// The Vue runtime, imported once. The generated render functions reference the global
// `Vue` (see `runtimeGlobalName` below), so the same object is passed into the compiled
// function's scope. Same trick the render-function store uses on the client.
//
// Guarded like the other two. Pwax vendors Vue as a browser global rather than an npm
// dependency, so an application that installs only the renderer and the compiler — which
// is what the documentation used to ask for — has no `vue` package for this to resolve.
let Vue;

try {
    Vue = await import('vue');
} catch {
    fail(
        `The optional peer dependency vue is not installed. Run ${install} in your ` +
            'application, or set pwax.ssr.enabled to false. Pwax serves Vue to the browser ' +
            'from its own vendored copy, but the SSR bridge needs it as a Node module.'
    );
}

/** The version a package reports for itself, or '' when its manifest is unreadable. */
const versionOf = (name) => {
    try {
        return require(`${name}/package.json`).version || '';
    } catch {
        // Resolvable as a module but not as a manifest — an unusual bundling, not a reason
        // to refuse. Skipping the comparison is the same position as not knowing.
        return '';
    }
};

for (const [name, installed] of [
    ['vue', versionOf('vue')],
    ['@vue/server-renderer', versionOf('@vue/server-renderer')],
    ['@vue/compiler-dom', versionOf('@vue/compiler-dom')],
]) {
    if (wanted && installed && installed !== wanted) {
        fail(
            `${name} ${installed} does not match the Vue runtime Pwax ships (${wanted}). ` +
                `Install the matching versions: ${install}`
        );
    }
}

const { renderToString } = renderer;
const { compile } = compiler;

/*
 * The Vue runtime, published as a global.
 *
 * A component module served by Pwax may dereference `Vue` at evaluation time. `pwax:compile`
 * prepends `const __pwaxRender = (() => { … })();` to a page's inline script, and the body
 * that `@vue/compiler-dom` generates opens with `const { … } = Vue` — an immediately-invoked
 * function, so the reference resolves the moment the module is imported, not when the
 * component renders. In the browser that global is the vendored `vue.global.prod.js`; in
 * Node there is none, and every prerender of a precompiled application failed with
 * `Vue is not defined` and fell back to the SPA shell. Precompiling is the recommended
 * setup, so that was most of the applications most likely to turn SSR on.
 */
globalThis.Vue = Vue;

/**
 * Evaluate a component's inline `script` as an ES module and return its default export.
 *
 * The script is the same string the browser receives as an inline module — `export
 * default { … }` — so evaluating it as a module yields the component options directly,
 * with no `new Function` and no CSP concern (this is Node, not the browser). A data: URL
 * is the simplest way to hand a string to `import()`; Node treats it as a module.
 *
 * @param {string} source
 * @returns {Promise<any>}
 */
async function evaluateModule(source) {
    if (!source || !source.trim()) {
        return {};
    }

    const url = 'data:text/javascript;charset=utf-8,' + encodeURIComponent(withSsrImports(source));

    return import(url);
}

/**
 * Compile a template string to a render function, using the same options as
 * `bin/compile-templates.mjs` so the server's output matches what the browser compiles.
 *
 * @param {string} template
 * @returns {Function}
 */
function compileTemplate(template) {
    const { code } = compile(template, {
        mode: 'function',
        hoistStatic: true,
        prefixIdentifiers: true,
        runtimeGlobalName: 'Vue',
        ssr: false,
    });

    // The generated chunk ends in `return function render(…)`, so evaluating it inside a
    // function yields the function itself — same trick the render-function store uses.
    return new Function('Vue', code)(Vue);
}

/**
 * Browser globals a component might reach for while rendering, in the order a message
 * should prefer to name them.
 */
const BROWSER_GLOBALS = ['window', 'document', 'navigator', 'localStorage', 'sessionStorage'];

/**
 * Say what went wrong in terms of the thing the developer can change.
 *
 * The raw failures here are not self-explanatory. `document is not defined` names a Node
 * fact rather than a Vue one, and arrives with a stack trace through a `data:` URL that
 * points at no file anybody wrote. `Failed to resolve component: Chart` does not mention
 * that prerendering is the reason. Each of these has exactly one cause and two or three
 * remedies, so the message says them.
 *
 * @param {string} message
 */
function explain(message) {
    const missing = /^(\w+) is not defined$/.exec(message);

    if (missing && BROWSER_GLOBALS.includes(missing[1])) {
        return (
            `pwax: \`${missing[1]}\` is not available while prerendering. A component that ` +
            'reads browser APIs as it renders cannot be server-rendered: move the code into ' +
            '`mounted()`, guard it with `typeof ' +
            `${missing[1]} !== 'undefined'\`, or mark the route \`->spaOnly()\`.`
        );
    }

    const unresolved = /Failed to resolve (component|directive): (\S+)/.exec(message);

    if (unresolved) {
        return (
            `pwax: the ${unresolved[1]} \`${unresolved[2]}\` could not be resolved while ` +
            'prerendering, so the HTML would not have matched what the browser builds. The ' +
            "bridge registers the page's own components and nothing else — application-wide " +
            'plugins and directives from `pwax.vue.*` are browser modules and cannot be ' +
            'loaded in Node. Import it on the page with `@pwaxImport`, or mark the route ' +
            '`->spaOnly()`.'
        );
    }

    return message;
}

/**
 * Turn a component payload into Vue component options.
 *
 * Mirrors `toComponentOptions` in the client runtime, in the same order, because a
 * difference here is a difference between the markup the server sends and the virtual DOM
 * the browser builds from the identical payload.
 *
 * The author's own `render` or `template` wins outright. Then the precompiled render
 * function, when `pwax:compile` produced one — using it rather than recompiling the
 * template is both faithful and one compile cheaper per prerender. Then the module's own
 * template, and finally the Blade template from the payload.
 *
 * @param {{template?: string, script?: string}} payload
 * @param {string} exportName selects a named export, as `@pwaxImport('X from view')` does
 */
async function toComponentOptions(payload, exportName = '') {
    const module = await evaluateModule(payload.script || '');

    if (exportName) {
        const named = module[exportName];

        if (!named) {
            throw new Error(`pwax: module has no export named "${exportName}"`);
        }

        return named;
    }

    // A function default export is the value itself, not options to merge — a functional
    // component. Spreading one yields `{}`.
    if (typeof module.default === 'function') {
        return module.default;
    }

    const options = { ...(module.default || {}) };

    if (!options.render && !options.template) {
        if (module.__pwaxRender) {
            options.render = module.__pwaxRender;
        } else if (module.__pwaxTemplate) {
            options.template = module.__pwaxTemplate;
        } else if (payload.template) {
            options.render = compileTemplate(payload.template);
        }
    }

    return options;
}

/**
 * The global the rewritten `@pwaxImport` call reaches for. See `withSsrImports()`.
 */
const SSR_IMPORT = '__pwaxSsrImport';

/**
 * Rewrite `@pwaxImport`'s emitted call so it does not need a `window`.
 *
 * `@pwaxImport('components.modal')` compiles to `window.pwax.component("/__pwax__/c/….js")`
 * — a property access on `window`, evaluated as the module loads. Node has no `window`, so
 * the import used to throw `ReferenceError: window is not defined` before a single element
 * was rendered, and every page with a sub-component fell back to the SPA shell.
 *
 * The obvious repair — declaring `globalThis.window` — is a trap. `typeof window ===
 * 'undefined'` is *the* idiom for "am I on the server", and a component that uses it
 * correctly would start taking the browser branch and reading `window.innerWidth` as
 * `undefined`: no error, a plausible-looking value, and markup that disagrees with the
 * browser's. Better to leave `window` genuinely absent, so that check keeps telling the
 * truth, and rewrite the one expression Pwax itself emits.
 *
 * The pattern is machine-generated by `Pwax::import()`, so matching it textually is safe;
 * a hand-written `window.pwax.component(…)` in someone's own `<script>` matches too, and
 * means exactly the same thing.
 *
 * @param {string} source
 */
function withSsrImports(source) {
    return source.replace(/window\s*\.\s*pwax\s*\.\s*component\s*\(/g, `${SSR_IMPORT}(`);
}

/**
 * Publish the import resolver the rewritten call above reaches for.
 *
 * The browser resolves a component URL over HTTP. Node cannot: it is a route on the
 * application currently serving this request, and a prerender that called back into its own
 * web server would be a request loop with a connection pool between it and a deadlock. So
 * the PHP side sends each imported component's source in `imports`, keyed by the same URL,
 * and this resolves against that map.
 *
 * `defineAsyncComponent`, exactly as the client does, and for the same reason: two
 * components that import each other are a supported arrangement, and resolving eagerly here
 * would recurse until the stack ran out. Deferring to render time breaks the cycle, and
 * `renderToString` awaits async components as it walks the tree.
 *
 * @param {Record<string, {template?: string, script?: string}>} imports
 */
function publishImports(imports) {
    /** @type {Map<string, any>} */
    const memo = new Map();

    const component = (url, exportName = '') => {
        const key = `${url}|${exportName}`;
        const cached = memo.get(key);

        if (cached) {
            return cached;
        }

        const async = Vue.defineAsyncComponent(async () => {
            const payload = imports[url];

            if (!payload) {
                // The map is built from the same call sites this resolves, so a miss means
                // the URL did not survive `Pwax::viewForUrl()` — a stale signature, or a
                // view the component allowlist refuses. Both 404 for the browser too.
                throw new Error(
                    `pwax: no source was provided for the imported component ${url}. ` +
                        'Check that the view is reachable and allowed by pwax.components.allowed.'
                );
            }

            return toComponentOptions(payload, exportName);
        });

        memo.set(key, async);

        return async;
    };

    globalThis[SSR_IMPORT] = component;
}

/**
 * Render one component to an HTML string.
 *
 * @returns {Promise<{ok: boolean, html?: string, serializedState?: any, errors?: Record<string,string>}>}
 */
async function renderOne() {
    const component = input.component || {};
    const data = input.data || {};
    const template = component.template || '';
    const script = component.script || '';
    const url = typeof input.url === 'string' ? input.url : '';

    // The markup fragments the client runtime renders, straight from `Shell::templates()`.
    // The defaults match the runtime's own when the payload carries none — the doctor's
    // probe, for instance, sends only a component.
    const templates = input.templates || {};
    const contentTemplate = templates.content || '<main><router-view></router-view></main>';

    // Before the page's module is evaluated, because `@pwaxImport` runs as it evaluates.
    publishImports(input.imports || {});

    const options = await toComponentOptions({ template, script });

    // Controller data is *not* passed as props. A Pwax component is a Blade view, so
    // `pwaxRender('pages.home', $data)` has already interpolated the data into the template
    // and the script by the time either reaches this script — the browser receives it baked
    // in and declares no props for it. Handing it over a second time here only risked
    // attribute fallthrough the client never renders.

    // Capture the resolved component state so the client hydration starts from the same
    // values the server rendered with. Vue clears the root instance after `renderToString`
    // returns, so reading `_instance` afterwards yields nulls; instead, wrap `data()` and
    // `setup()` to retain their results.
    let captured = { ...data };

    if (typeof options.data === 'function') {
        const originalData = options.data;
        options.data = function ssrDataCapture(...args) {
            const result = originalData.apply(this, args);
            captured = { ...captured, ...result };
            return result;
        };
    }

    if (typeof options.setup === 'function') {
        const originalSetup = options.setup;
        options.setup = function ssrSetupCapture(...args) {
            const result = originalSetup.apply(this, args);
            if (result && typeof result.then === 'function') {
                return result.then((resolved) => {
                    captured = { ...captured, ...resolved };
                    return resolved;
                });
            }
            captured = { ...captured, ...result };
            return result;
        };
    }

    const app = Vue.createSSRApp({ template: contentTemplate });

    /*
     * A component that throws must fail the prerender, not shrink it.
     *
     * Vue's default is to warn and carry on, and an async component that cannot load
     * renders as an empty comment — so a page whose `@pwaxImport` did not resolve came back
     * `ok: true` with the sub-component simply missing from the HTML. That is the worst
     * available outcome: the markup ships, the crawler indexes a page with a hole in it, and
     * the browser then finds a comment where it expected an element and re-renders the
     * subtree. Falling back to the SPA shell is strictly better than half a page.
     */
    const failures = [];

    app.config.errorHandler = (error) => {
        failures.push(explain(error && error.message ? error.message : String(error)));
    };

    /*
     * A warning about something Vue could not resolve is a failure here.
     *
     * In the browser it is recoverable — the component or directive is usually registered
     * by an application plugin that has since loaded. In a prerender nothing arrives later:
     * an unresolved component is emitted as a literal element of that name and an
     * unresolved directive is silently dropped, so the markup ships looking plausible and
     * disagrees with the browser's the moment it hydrates. Every other warning is a
     * diagnostic and goes to stderr, where it cannot corrupt the result on stdout.
     */
    app.config.warnHandler = (message) => {
        if (/Failed to resolve (component|directive)/.test(message)) {
            failures.push(explain(message));

            return;
        }

        process.stderr.write(`[Vue warn]: ${message}\n`);
    };

    /*
     * `router-view` renders the *page component wrapper*, not the page component.
     *
     * This is the difference between HTML that hydrates and HTML that does not. On the
     * client, `<router-view>` resolves to `PwaxPage`, whose template has two root-level
     * `<template>` blocks — so it is a fragment, and Vue brackets its output with
     * `<!--[-->` / `<!--]-->` anchors and leaves a `<!---->` placeholder where the loader
     * branch did not render. Hydration walks those nodes and expects every one of them.
     *
     * Rendering the page component directly here produced the right elements and none of
     * the anchors, so `createSSRApp` bailed out on the very first node, threw the server's
     * markup away and drew the page again on the client — the prerender was doing nothing
     * but costing a Node process. Building the stand-in from `pageTemplate()`, the same
     * function `src/js/page.js` uses, means the two structures cannot disagree.
     *
     * The state below is the success branch, which is the only branch a prerender reaches:
     * an error would have been reported instead of rendered.
     */
    app.component('router-view', {
        name: 'PwaxPage',
        template: pageTemplate(templates),
        data: () => ({
            component: Vue.markRaw(options),
            loading: false,
            error: null,
            currentPath: url,
            renderedPath: url,
            announced: true,
        }),
    });

    /*
     * `router-link`, rendering exactly what Vue Router's own `RouterLink` renders.
     *
     * Vue Router is not installed here — Pwax vendors it as a browser global — so the
     * bridge stands in for it. "Roughly an `<a>`" is not close enough: this was written as
     * a `template` with a `<slot>`, and a compiled slot outlet is a fragment, so the server
     * wrapped every link's text in `<!--[-->` / `<!--]-->` anchors that `RouterLink` does
     * not produce. That is a node-structure mismatch on any page with a nav.
     *
     * A render function passing the slot's vnodes straight through as array children emits
     * them inline, as `RouterLink` does. `aria-current` and the active classes are matched
     * too — those are only attributes, which Vue would patch rather than bail on, but a
     * patch is still a wasted DOM write and a "hydration completed but contains mismatches"
     * line in everyone's console.
     */
    app.component('router-link', {
        props: { to: { type: [String, Object], default: '' } },
        render() {
            const href = typeof this.to === 'string' ? this.to : this.to.path || '';
            const active = href !== '' && url !== '' && url.split('?')[0] === href;

            return Vue.h(
                'a',
                {
                    'aria-current': active ? 'page' : null,
                    href,
                    // An empty string, not null, for an inactive link: `RouterLink` always
                    // binds the class, so the attribute is present either way.
                    class: active ? 'router-link-active router-link-exact-active' : '',
                },
                this.$slots.default ? this.$slots.default() : []
            );
        },
    });

    const html = await renderToString(app);

    if (failures.length) {
        return { ok: false, message: failures[0], errors: { render: failures.join('\n') } };
    }

    let serializedState = data;

    try {
        // Prefer the captured state; fall back to the input data for components that
        // consumed it purely as props (no `data()` or `setup()`).
        serializedState = JSON.parse(JSON.stringify(captured));
    } catch {
        serializedState = data;
    }

    return { ok: true, html, serializedState };
}

try {
    const result = await renderOne();
    write(result);
} catch (error) {
    write({
        ok: false,
        message: explain(error && error.message ? error.message : String(error)),
        errors: { _: error && error.stack ? String(error.stack) : String(error) },
    });
}
