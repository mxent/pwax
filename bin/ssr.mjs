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

    const url = 'data:text/javascript;charset=utf-8,' + encodeURIComponent(source);

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

    const module = await evaluateModule(script);
    const options =
        typeof module.default === 'function' ? module.default() : { ...(module.default || {}) };

    // Mirrors `toComponentOptions` in the client runtime, in the same order, because a
    // difference here is a difference between the markup the server sends and the virtual
    // DOM the browser builds from the identical payload.
    //
    // The author's own `render` or `template` wins outright. Then the precompiled render
    // function, when `pwax:compile` produced one — using it rather than recompiling the
    // template is both faithful and one compile cheaper per prerender. Then the module's
    // own template, and finally the Blade template from the payload.
    if (!options.render && !options.template) {
        if (module.__pwaxRender) {
            options.render = module.__pwaxRender;
        } else if (module.__pwaxTemplate) {
            options.template = module.__pwaxTemplate;
        } else if (template) {
            options.render = compileTemplate(template);
        }
    }

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
        message: error && error.message ? error.message : String(error),
        errors: { _: error && error.stack ? String(error.stack) : String(error) },
    });
}
