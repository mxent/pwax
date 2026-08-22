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

import { writeSync } from 'node:fs';
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

/**
 * Write the whole payload to stdout before returning.
 *
 * `process.stdout.write` on a pipe is asynchronous, so exiting straight after it can
 * truncate the JSON — which the PHP side reports as "the SSR bridge did not return JSON".
 * Deferring the exit to the write callback fixes that but breaks the other caller: `fail()`
 * has to stop the script where it stands, and an exit scheduled for later lets the next
 * line run and throw over the top of the message it just wrote. A synchronous write to the
 * descriptor satisfies both.
 *
 * The loop is for partial writes, which a pipe is allowed to do, and `EAGAIN`, which it
 * returns when its buffer is momentarily full.
 *
 * @param {string} text
 */
function emit(text) {
    const buffer = Buffer.from(text, 'utf8');
    let written = 0;

    while (written < buffer.length) {
        try {
            written += writeSync(1, buffer, written, buffer.length - written);
        } catch (error) {
            if (error && error.code === 'EAGAIN') {
                continue;
            }

            throw error;
        }
    }
}

/**
 * Write the result and end the process.
 *
 * The exit is not housekeeping. This is a one-shot bridge, but the application it rendered
 * may have left the event loop with work on it — a `setInterval` for a clock or a poller is
 * the ordinary case — and Node will not exit while that is scheduled. The JSON was already
 * on stdout, and the PHP side still waited out the whole `ssr.timeout`, killed the process,
 * and served the SPA shell: a page that had rendered perfectly well, thrown away, at the
 * cost of the full timeout. `dom.window.close()` does not help, because a timer the
 * component scheduled through the global scope is Node's, not jsdom's.
 *
 * Exit is deferred to the write callback rather than called straight after `write`, because
 * stdout on a pipe is asynchronous — exiting immediately can truncate the JSON, which the
 * PHP side reports as "the SSR bridge did not return JSON".
 */
const finish = (payload) => {
    emit(JSON.stringify(payload));
    process.exit(0);
};

const write = finish;

const fail = (message, extra = {}) => {
    finish({ ok: false, message, ...extra });
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
        // Off, and not for the reason the name suggests.
        //
        // `@vue/compiler-dom`'s `compile()` hardwires `transformHoist: stringifyStatic`
        // when it is not the browser build — the browser build passes `null` — and that
        // transform only ever runs when static hoisting is on. It rewrites a hoisted
        // static subtree into a `createStaticVNode('<html string>')` the runtime mounts
        // by assigning `innerHTML`, so those elements never reach `patchProp` and the
        // markup comes from the compiler's own serialiser instead of from the DOM.
        //
        // The two serialisers disagree. `:required="true"` is stringified as
        // `required="true"`, where an element built node-by-node — which is what the
        // browser does, because it compiles the same template with the build that has
        // the transform off — carries `required=""`. Same for `readonly`, `open`,
        // `controls` and every other attribute whose presence is its value.
        //
        // Hoisting is a codegen optimisation and stringifying is part of it, so there is
        // no supported way to keep one and drop the other. Turning it off costs a little
        // work per render and buys markup identical to the browser's, which is the whole
        // promise of the prerender.
        hoistStatic: false,
        prefixIdentifiers: true,
        runtimeGlobalName: 'Vue',
        ssr: false,
        // Pinned, not left to default. `comments` defaults to whether the compiler is a
        // development build, and this bridge deliberately runs the development one so that
        // Vue's resolution warnings exist. Left alone, the server kept every HTML comment in
        // a template and the browser — compiling the same template with the *production*
        // runtime Pwax ships — dropped them, so any component containing `<!-- … -->`
        // hydrated with a mismatch. `false` is what the browser does.
        comments: false,
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
    const settle = () => input.settle === true;
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

    /*
     * Build the app: shared between the fast `renderToString` path and the DOM-based
     * settle path. Both need the same component registrations, error/warn handlers and
     * state capture, so the only branch is the render strategy at the end.
     *
     * `ssr` selects between `createSSRApp` (for `renderToString`) and `createApp` (for the
     * DOM-based settle path). The settle path mounts to a fresh jsdom document, so there is
     * nothing to hydrate — `createSSRApp` would warn "container is empty" and fall back to
     * a full mount anyway, but its internal hydration bookkeeping can interfere with the
     * mount. `createApp` does exactly what the browser does: mount from scratch.
     */
    const failures = [];

    const buildApp = (ssr = true) => {
        const app = (ssr ? Vue.createSSRApp : Vue.createApp)({ template: contentTemplate });

        app.config.errorHandler = (error) => {
            failures.push(explain(error && error.message ? error.message : String(error)));
        };

        app.config.warnHandler = (message) => {
            const unresolved = /Failed to resolve (component|directive): (\S+)/.exec(message);

            if (unresolved && !(unresolved[1] === 'component' && unresolved[2].includes('-'))) {
                failures.push(explain(message));

                return;
            }

            process.stderr.write(`[Vue warn]: ${message}\n`);
        };

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
                        class: active ? 'router-link-active router-link-exact-active' : '',
                    },
                    this.$slots.default ? this.$slots.default() : []
                );
            },
        });

        return app;
    };

    /*
     * The settle path: mount to a jsdom document, let lifecycle hooks run, wait for
     * the app to become stable, and serialise the final DOM.
     *
     * This is what captures the things `renderToString` cannot: a script injecting
     * `<style>` tags in `mounted()`, a `fetch` that populates a list, any DOM
     * mutation that happens after the initial render. The result is the full page as the
     * browser's first paint would show it — complete for crawlers and no-JS visitors.
     *
     * The client does *not* hydrate this HTML: the DOM may carry content the
     * synchronous virtual DOM does not (the fetched list, the injected styles), so
     * `createSSRApp`'s node-by-node comparison would bail out and re-render anyway.
     * Instead the client re-renders from scratch — slower for it, but the prerendered HTML
     * was for the crawler, and the swap is invisible to a visitor whose page has already
     * painted. This is the same trade Angular Universal makes when hydration is not
     * available, and it is the right one for content that is not knowable synchronously.
     */
    if (settle()) {
        return await renderWithDom(options, templates, contentTemplate, failures, captured, data);
    }

    const app = buildApp();

    /*
     * `<Teleport>` renders its children somewhere else in the document — `body`, usually —
     * and the server renderer collects them here instead of putting them in the returned
     * string. Placing them would mean the shell knowing every teleport target in advance,
     * which it cannot: `to` is a selector, and it can be computed.
     *
     * So the markup would ship without the teleported content in it, which for the thing
     * people teleport — a modal, a dropdown, a dialog — is often the content worth
     * prerendering, and the browser would then find nothing to hydrate at the target. A page
     * that actually teleports something during the prerender fails; one whose modal is
     * closed teleports nothing and is unaffected.
     */
    const context = {};
    const html = await renderToString(app, context);

    if (Object.keys(context.teleports || {}).length > 0) {
        failures.push(
            'pwax: this page renders a `<Teleport>`, whose content belongs outside the mount ' +
                'element and cannot be placed by the prerenderer — the markup would ship without ' +
                'it and the browser would find nothing to hydrate at the target. Render the ' +
                'content in place, or mark the route `->spaOnly()`.'
        );
    }

    if (failures.length) {
        return { ok: false, message: failures[0], errors: { render: failures.join('\n') } };
    }

    // Prefer the captured state; fall back to the input data for components that consumed
    // it purely as props (no `data()` or `setup()`).
    const { state: serializedState, unseeded } = seedable(captured, data);

    return { ok: true, html: devAnchors(html), serializedState, unseeded };
}

/**
 * Render by mounting to a jsdom document and waiting for post-mount behaviour.
 *
 * Requires `jsdom` as an optional peer dependency. When it is not installed, the bridge
 * reports the failure with the install command rather than crashing — the same pattern the
 * missing-dependency guards at the top of this file use.
 *
 * The client does not hydrate this HTML (see `renderOne`'s docblock). The result carries
 * `hydrate: false` so the PHP side can tell the runtime to re-render rather than attempt
 * hydration.
 *
 * @param {any} options  The resolved page component options.
 * @param {Record<string, string>} templates  The markup fragments from `Shell::templates()`.
 * @param {string} contentTemplate  The root content template.
 * @param {string[]} failures  Shared failure collector.
 * @param {Record<string, any>} captured  The captured state from `data()`/`setup()`.
 * @param {Record<string, any>} data  The controller data.
 * @returns {Promise<{ok: boolean, html?: string, serializedState?: any, unseeded?: string[], hydrate?: boolean, settled?: boolean, pending?: number}>}
 */

/**
 * Count the asynchronous work the application still has outstanding.
 *
 * This is the part a DOM diff cannot do. "Nothing changed in the last round" is not
 * "nothing is pending": a `fetch` in `mounted()` has not touched the DOM yet a
 * millisecond after it was issued, so a renderer that watches only the DOM calls the page
 * stable before the request has even left. Measured against the previous implementation,
 * anything slower than about ten milliseconds — which is every real HTTP call — was
 * dropped, and the bridge still reported success.
 *
 * Angular's `ApplicationRef.isStable` does not watch the DOM either; Zone.js counts
 * pending macrotasks and the app is stable when the count reaches zero. This is the same
 * idea with a much smaller net: the two ways application code starts work that will later
 * change the DOM.
 *
 *   - `setTimeout`, because a component that reveals content on a tick uses one, and
 *     because `await`ing anything scheduled on a timer goes through it.
 *   - `fetch` and `XMLHttpRequest`, because that is how the data arrives.
 *
 * `setInterval` is deliberately *not* counted. A repeating timer is never "done", so
 * counting it would hold every page with a clock or a poller until the ceiling, and the
 * DOM-quiet check below already handles those correctly.
 *
 * Both the Node globals and the jsdom window are patched: component code reaches
 * `setTimeout` and `fetch` through the global scope, but a library may hold `window`.
 *
 * @param {Window} window  The jsdom window.
 * @param {number} ceiling  Milliseconds after which nothing new is worth waiting for.
 */
/**
 * Bindings Vue sets as DOM properties rather than as attributes.
 *
 * `innerHTML` is the one that matters — it is what `v-html` compiles to — and the rest are
 * the properties whose attribute form does not track the live value.
 */
const DOM_PROPS = new Set(['innerHTML', 'textContent', 'value', 'checked', 'selected', 'muted']);

/**
 * Of those, the ones that also appear as an attribute in the serialised markup.
 *
 * Setting `el.value` alone leaves nothing in `outerHTML`, so a prerendered form arrived at
 * the crawler — and at a visitor without JavaScript — with every field blank, every
 * checkbox clear and no option selected, while the browser rendering the same component
 * showed them filled. `innerHTML` and `textContent` are deliberately absent: their content
 * is the element's children, not an attribute.
 */
const REFLECTED_PROPS = new Set(['value', 'checked', 'selected', 'muted']);

/**
 * Vue's development compiler labels the placeholder comment left where a `v-if` did not
 * render; the production compiler the browser runs emits a bare `<!---->`.
 *
 * The bridge compiles with the development build on purpose — `warn()` is how an unresolved
 * component or directive is caught, and production strips it — so the labels are the price
 * of the diagnostics rather than something to fix by switching builds. Rewriting them on
 * the way out costs nothing and is what makes the server's markup identical to the
 * browser's, on both render paths.
 *
 * Only the labels go. `<!--[-->` and `<!--]-->` are the fragment anchors the client's
 * hydration walks, and the browser produces those too.
 */
const devAnchors = (html) => html.replace(/<!--(?:v-if|v-else|v-else-if|v-for)-->/g, '<!---->');

/**
 * Attributes whose presence is the value, so `false` means "leave it out".
 *
 * Everywhere else `false` is an ordinary value and renders as the string `"false"` — which
 * is what the browser does, and what this got wrong: `:data-active="false"` and
 * `:aria-hidden="false"` were dropped from the server's markup and present in the
 * browser's. The `aria-` case is not cosmetic. `aria-hidden="false"` and no `aria-hidden`
 * at all mean different things to a screen reader once an ancestor has hidden the subtree.
 *
 * The rule is Vue's own: remove when the value is falsy and not the empty string, and emit
 * the empty string otherwise.
 */
const BOOLEAN_ATTRS = new Set([
    'allowfullscreen',
    'async',
    'autofocus',
    'autoplay',
    'controls',
    'default',
    'defer',
    'disabled',
    'formnovalidate',
    'inert',
    'ismap',
    'itemscope',
    'loop',
    'multiple',
    'nomodule',
    'novalidate',
    'open',
    'playsinline',
    'readonly',
    'required',
    'reversed',
]);

const setTimeoutOriginal = globalThis.setTimeout;

/**
 * Sleep on the timer this module captured at import time.
 *
 * `trackActivity` replaces `globalThis.setTimeout` for the duration of a settle render, so
 * the poll below has to hold the original — scheduling its own rounds through the patched
 * one would count them as outstanding application work and the app would never be stable.
 *
 * @param {number} ms
 */
function sleep(ms) {
    return new Promise((resolve) => setTimeoutOriginal(resolve, ms));
}

function trackActivity(window, ceiling) {
    let pending = 0;
    const restore = [];
    const started = Date.now();

    /**
     * Resolve a request URL the way the browser would.
     *
     * `fetch('/api/items')` is what an application writes, and in a browser it resolves
     * against the document. Node's `fetch` has no document to resolve against and throws
     * `Failed to parse URL from /api/items` — so the ordinary shape of the ordinary case,
     * a list fetched in `mounted()`, failed the whole prerender.
     *
     * Only bare strings are touched. A `Request` or a `URL` already carries an absolute
     * URL, because constructing either from a relative one would itself have thrown.
     */
    const absolute = (resource) => {
        if (typeof resource !== 'string') {
            return resource;
        }

        try {
            return new URL(resource, window.location.href).href;
        } catch {
            return resource;
        }
    };

    const targets = window === globalThis ? [globalThis] : [globalThis, window];

    for (const target of targets) {
        const originalSetTimeout = target.setTimeout;
        const originalClearTimeout = target.clearTimeout;

        if (typeof originalSetTimeout === 'function') {
            const live = new Set();

            target.setTimeout = function pwaxSetTimeout(handler, delay, ...args) {
                // A timer that cannot fire before the ceiling is not worth holding the
                // render for — it would only ever end in the timeout.
                const remaining = ceiling - (Date.now() - started);
                const counted = typeof handler === 'function' && (Number(delay) || 0) <= remaining;

                const handle = originalSetTimeout.call(
                    this,
                    (...called) => {
                        if (live.delete(handle)) {
                            pending -= 1;
                        }

                        if (typeof handler === 'function') {
                            handler(...called);
                        }
                    },
                    delay,
                    ...args
                );

                if (counted) {
                    live.add(handle);
                    pending += 1;
                }

                return handle;
            };

            target.clearTimeout = function pwaxClearTimeout(handle) {
                if (live.delete(handle)) {
                    pending -= 1;
                }

                return originalClearTimeout.call(this, handle);
            };

            restore.push(() => {
                target.setTimeout = originalSetTimeout;
                target.clearTimeout = originalClearTimeout;
            });
        }

        const originalFetch = target.fetch;

        if (typeof originalFetch === 'function') {
            target.fetch = function pwaxFetch(resource, ...rest) {
                pending += 1;

                return Promise.resolve(
                    originalFetch.call(this, absolute(resource), ...rest)
                ).finally(() => {
                    // One tick of grace: the caller almost always chains `.json()`, and
                    // releasing the count in the same microtask would let the poll declare
                    // the app stable before the body is read and rendered.
                    pending += 1;
                    queueMicrotask(() => {
                        pending -= 2;
                    });
                });
            };

            restore.push(() => {
                target.fetch = originalFetch;
            });
        }
    }

    const XHR = window.XMLHttpRequest;

    if (typeof XHR === 'function' && typeof XHR.prototype?.send === 'function') {
        const originalSend = XHR.prototype.send;

        XHR.prototype.send = function pwaxSend(...args) {
            pending += 1;

            let settled = false;
            const release = () => {
                if (!settled) {
                    settled = true;
                    pending -= 1;
                }
            };

            this.addEventListener('loadend', release);
            // `loadend` fires for success, error and abort alike, but a listener added on a
            // request that never completes would hold the count — the ceiling covers that.

            try {
                return originalSend.apply(this, args);
            } catch (error) {
                release();
                throw error;
            }
        };

        restore.push(() => {
            XHR.prototype.send = originalSend;
        });
    }

    return {
        get pending() {
            return pending;
        },
        restore() {
            for (const undo of restore) {
                undo();
            }
        },
    };
}

/**
 * Wait for the app to become stable, the way Angular's `ApplicationRef.isStable` does.
 *
 * Two conditions, and both are needed. The activity counter says whether anything the
 * application started is still outstanding; the DOM comparison says whether the result of
 * that work has been rendered yet. Waiting on the counter alone would serialise in the
 * microtask between a response arriving and Vue flushing it to the DOM; waiting on the DOM
 * alone — which is what this did — returns before the work has begun.
 *
 * The DOM has to be unchanged across two consecutive rounds, not one. A single quiet round
 * is satisfied by the gap between two renders of a component that updates in stages.
 *
 * The whole document is compared, not just the mount element. A page that appends a toast
 * container to `<body>` or injects a `<style>` into `<head>` from `mounted()` is doing
 * exactly what this mode exists to capture, and watching only `#pwax` declared those pages
 * stable while the work was still in flight.
 *
 * `ceiling` is the safety net rather than the strategy: a page that never settles — a
 * polling interval, a reconnect loop — is abandoned there and whatever has rendered is
 * serialised.
 *
 * @param {Window} window  The jsdom window.
 * @param {{pending: number}} activity  The counter from {@see trackActivity}.
 * @param {number} ceiling  Hard ceiling in milliseconds.
 */
async function waitForStable(window, activity, ceiling) {
    const round = 10;
    const start = Date.now();
    const snapshot = () => window.document.documentElement.outerHTML;

    let previous = snapshot();
    let quiet = 0;

    for (;;) {
        // Flush microtasks (promise chains from mounted/setup), then wait a round for any
        // timer scheduled during that flush.
        await sleep(0);
        await sleep(round);

        const current = snapshot();

        if (current === previous && activity.pending <= 0) {
            quiet += 1;

            if (quiet >= 2) {
                return true;
            }
        } else {
            quiet = 0;
        }

        previous = current;

        if (Date.now() - start >= ceiling) {
            return false;
        }
    }
}

/**
 * The absolute URL to give the jsdom document.
 *
 * `input.url` is the request URI — `/about`, `/posts?page=2` — because that is what the
 * router-link and `currentPath` comparisons below need. jsdom needs an *absolute* URL, and
 * throws `Invalid URL: /about` when handed a path, which failed every settle render in
 * production while the tests, which send no `url` at all, stayed green.
 *
 * `input.origin` is the scheme and host of the request being served, so `window.location`
 * matches the page the browser would be on. Falling back to `http://localhost` keeps a
 * payload without one working.
 */
function documentUrl() {
    const origin =
        typeof input.origin === 'string' && input.origin !== '' ? input.origin : 'http://localhost';
    const path = typeof input.url === 'string' && input.url !== '' ? input.url : '/';

    try {
        return new URL(path, origin).href;
    } catch {
        return 'http://localhost/';
    }
}

async function renderWithDom(options, templates, contentTemplate, failures, captured, data) {
    const url = typeof input.url === 'string' ? input.url : '';
    let JSDOM;

    try {
        ({ JSDOM } = await import('jsdom'));
    } catch {
        fail(
            'The optional peer dependency jsdom is not installed. Run ' +
                '`npm install --save-dev jsdom` in your application, or set pwax.ssr.settle to false.'
        );
    }

    // The ceiling for the stability poll, in milliseconds. Derived from `ssr.timeout`
    // (seconds) with a floor of 1 second so a short timeout still leaves room for a
    // render round. The bridge polls until the document is quiet rather than sleeping
    // for a fixed duration, in the same way Angular's `ApplicationRef.isStable` waits
    // for the app to settle. The ceiling is the safety net, not the strategy.
    // Four-fifths of the Node timeout, not all of it. `ssr.timeout` is also what Symfony's
    // `Process` waits before killing this script, so a ceiling equal to it meant a page that
    // used the whole budget was killed at the very moment it finished settling — with the
    // HTML rendered, nothing written, and the SPA served instead.
    const budget = Math.max(1000, Math.min(30000, (Number(input.timeout) || 5) * 1000));

    // Measured against what is *left* of the budget, not against the whole of it. Node
    // spends a few hundred milliseconds starting and importing Vue before this line runs,
    // and `Process` has been counting since before that — so a ceiling of the full budget
    // put the poll's deadline past the moment PHP kills the script.
    const elapsed = process.uptime() * 1000;
    const ceiling = Math.max(500, Math.floor(budget * 0.8) - elapsed);

    // A document whose body mirrors the shell's mount element, so the app mounts into the
    // same container it will in the browser. `pretendToBeVisual` enables `requestAnimationFrame`,
    // which some components and libraries reach for in `mounted()`.
    const dom = new JSDOM(
        '<!DOCTYPE html><html><head></head><body><div id="pwax"></div></body></html>',
        { pretendToBeVisual: true, url: documentUrl() }
    );

    const { window } = dom;
    const doc = window.document;

    // Vue's `runtime-dom` CJS build captures `document` in a closure when the module is
    // first imported — at the top of this file, where `document` is undefined. So
    // `createApp(...).mount()` reaches for a null `document` and crashes. The fix is to
    // build a renderer whose `nodeOps` operate on the jsdom document directly, using
    // `createRenderer` with a shallow copy of Vue's own `nodeOps` retargeted to `doc`.
    //
    // `@vue/runtime-dom` exports both `nodeOps` and `createRenderer`; the latter takes
    // `nodeOps` and `patchProp` and returns a `createApp` that uses them. This is the
    // supported extension point for rendering to a non-browser DOM.
    const runtimeDom = await import('@vue/runtime-dom');
    const jsdomNodeOps = {
        insert(child, parent, anchor) {
            if (anchor !== null) {
                parent.insertBefore(child, anchor);
            } else {
                parent.appendChild(child);
            }
        },
        remove(child) {
            const parent = child.parentNode;
            if (parent) parent.removeChild(child);
        },
        createElement(tag, _isSVG, _isCustom) {
            return doc.createElement(tag);
        },
        createText(text) {
            return doc.createTextNode(text);
        },
        createComment(text) {
            return doc.createComment(text);
        },
        setText(node, text) {
            node.textContent = text;
        },
        setElementText(el, text) {
            el.textContent = text;
        },
        parentNode(node) {
            return node.parentNode;
        },
        nextSibling(node) {
            return node.nextSibling;
        },
        querySelector(selector) {
            return doc.querySelector(selector);
        },
        setScopeId(el, id) {
            el.setAttribute(id, '');
        },
        insertStaticContent(content, parent, anchor, _isSVG) {
            const tpl = doc.createElement('template');
            tpl.innerHTML = content;
            const nodes = [...tpl.content.childNodes];
            nodes.forEach((n) => parent.insertBefore(n, anchor));
            return nodes;
        },
    };

    // Vue's runtime-dom dereferences `SVGElement` and `SVGAnimatedString` from the global
    // scope (not from `window`) when mounting. jsdom does not provide them, so they must
    // be published on `globalThis` for the duration of the mount. Saved and restored so
    // a subsequent render in the same process (the doctor's probe) is unaffected.
    const savedSvgElement = globalThis.SVGElement;
    const savedSvgAnimatedString = globalThis.SVGAnimatedString;

    globalThis.SVGElement = window.SVGElement || window.Element;
    globalThis.SVGAnimatedString =
        window.SVGAnimatedString ||
        class SVGAnimatedString {
            constructor() {
                this.baseVal = '';
                this.animVal = '';
            }
        };

    // Vue's createApp reads `document` and `window` from the global scope. Publish them
    // for the duration of the render so the app mounts to the jsdom document rather than
    // throwing `document is not defined`. Saved and restored so a second render in the
    // same process (the doctor's probe) is unaffected.
    const savedDocument = globalThis.document;
    const savedWindow = globalThis.window;

    globalThis.document = doc;
    globalThis.window = window;

    /** @type {{pending: number, restore: () => void}|null} */
    let activity = null;

    try {
        // Build the app with a renderer that targets the jsdom document. `createRenderer`
        // returns `{ createApp }` just like `Vue.createApp`, but its host functions operate
        // on `doc` rather than the cached null `document`. The `patchProp` is a minimal
        // implementation that covers the attribute types a prerendered page touches.
        const renderer = runtimeDom.createRenderer({
            ...jsdomNodeOps,
            patchProp(el, key, _prev, next) {
                if (key === 'class') {
                    el.className = next ?? '';

                    return;
                }

                if (key === 'style') {
                    if (typeof next === 'object' && next !== null) {
                        for (const [name, value] of Object.entries(next)) {
                            el.style[name] = value;
                        }
                    } else if (next == null) {
                        el.removeAttribute('style');
                    } else {
                        el.setAttribute('style', String(next));
                    }

                    return;
                }

                if (key.startsWith('on') && typeof next === 'function') {
                    el.addEventListener(key.slice(2).toLowerCase(), next);

                    return;
                }

                // Some bindings are DOM *properties*, and setting them as attributes is
                // not a near-enough approximation — it is a different thing entirely.
                // `v-html` compiles to an `innerHTML` binding, so writing it as an
                // attribute produced `<div innerhtml="&lt;strong&gt;…">`: the markup
                // escaped into an attribute value, rendering nothing, on a feature the
                // README lists as working under SSR.
                if (DOM_PROPS.has(key)) {
                    el[key] = next ?? '';

                    if (REFLECTED_PROPS.has(key)) {
                        if (next === false || next == null) {
                            el.removeAttribute(key);
                        } else {
                            el.setAttribute(key, next === true ? '' : String(next));
                        }
                    }

                    return;
                }

                if (next == null) {
                    el.removeAttribute(key);

                    return;
                }

                if (BOOLEAN_ATTRS.has(key)) {
                    // Vue's `includeBooleanAttr`: present unless the value is falsy, with
                    // the empty string counting as present.
                    if (next || next === '') {
                        el.setAttribute(key, '');
                    } else {
                        el.removeAttribute(key);
                    }

                    return;
                }

                el.setAttribute(key, String(next));
            },
        });

        const app = renderer.createApp({ template: contentTemplate });

        app.config.errorHandler = (error) => {
            failures.push(explain(error && error.message ? error.message : String(error)));
        };

        app.config.warnHandler = (message) => {
            const unresolved = /Failed to resolve (component|directive): (\S+)/.exec(message);

            if (unresolved && !(unresolved[1] === 'component' && unresolved[2].includes('-'))) {
                failures.push(explain(message));

                return;
            }

            process.stderr.write(`[Vue warn]: ${message}\n`);
        };

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
                        class: active ? 'router-link-active router-link-exact-active' : '',
                    },
                    this.$slots.default ? this.$slots.default() : []
                );
            },
        });

        // Started before the mount, so work scheduled by a `mounted()` hook on the very
        // first render is counted.
        activity = trackActivity(window, ceiling);

        app.mount(doc.getElementById('pwax'));

        /*
         * Wait for the app to become stable before serialising the DOM.
         *
         * Angular's `ApplicationRef.isStable` is the model: the server render waits for
         * the app to settle — all pending microtasks, timers and async work drained —
         * rather than sleeping for a fixed duration. A fixed delay guesses how long a
         * `fetch` or a style-injecting script will take; polling until quiet does not.
         *
         * The poll flushes microtasks with `await`, then checks whether the document
         * is still mutating. If it is, it waits another round and checks again. The
         * `ceiling` is the safety net: a page that never settles (a polling interval, a
         * reconnect loop) is abandoned at the timeout, and whatever has rendered so far
         * is serialised — which is the same outcome a fixed delay produces, just without
         * the guesswork about how long to wait.
         */
        const settled = await waitForStable(window, activity, ceiling);

        if (failures.length) {
            return { ok: false, message: failures[0], errors: { render: failures.join('\n') } };
        }

        // Serialise the mount element's content. This is what the browser's first paint
        // would show: the initial render plus everything `mounted()` added — injected
        // styles, fetched data, DOM mutations.
        const html = devAnchors(window.document.getElementById('pwax').innerHTML);

        if (!html || html.trim() === '') {
            failures.push('pwax: the page produced no content after settling.');

            return { ok: false, message: failures[0] };
        }

        // Anything the application put in `<body>` outside the mount element. A toast
        // container, a modal portal, a cookie banner appended in `mounted()` — all of it is
        // part of the page the browser paints, and serialising only `#pwax` left it out of
        // the document a crawler reads. Returned separately because it belongs *beside* the
        // mount element in the shell, not inside it.
        const bodyHtml = [];

        for (const node of window.document.body.children) {
            if (node.id !== 'pwax' && node.outerHTML) {
                bodyHtml.push(devAnchors(node.outerHTML));
            }
        }

        // Capture any `<style>` tags injected into `<head>` during the render —
        // libraries that inject styles at mount time do this. These travel back to PHP
        // so the shell can put them in the real `<head>`, where they are visible to
        // crawlers and present before the client re-renders.
        const headStyles = [];

        for (const style of window.document.head.querySelectorAll('style')) {
            if (style.textContent && style.textContent.trim()) {
                headStyles.push(style.textContent);
            }
        }

        const { state: serializedState, unseeded } = seedable(captured, data);

        /*
         * `hydrate: false` tells the PHP side to mark the initial payload so the client
         * re-renders rather than hydrating. The settled DOM may carry content the
         * synchronous virtual DOM does not, so hydration would bail out anyway.
         * `headStyles` carries any styles injected during the settle, so the shell can
         * inline them in `<head>`.
         *
         * `settled: false` means the poll gave up at the ceiling with work still in flight,
         * so what follows is a partial page — the list that was still being fetched is not
         * in it, and neither is anything that depended on it.
         *
         * It is reported rather than failed on, because a partial prerender is still better
         * than none and the client fills in the rest on boot. But it is worth saying: the
         * commonest cause is not a slow API at all. A page whose `mounted()` fetches its own
         * application cannot be prerendered by a single-worker development server — the one
         * process is busy serving this very request, so the fetch waits for a reply that
         * cannot come until it returns. `php artisan serve` runs one worker unless
         * `PHP_CLI_SERVER_WORKERS` says otherwise, and every production server runs more.
         */
        return {
            ok: true,
            html,
            serializedState,
            unseeded,
            hydrate: false,
            headStyles,
            bodyHtml,
            settled,
            pending: Math.max(0, activity.pending),
        };
    } finally {
        // Before the globals are put back, since the patches live on them.
        activity?.restore();

        globalThis.document = savedDocument;
        globalThis.window = savedWindow;
        globalThis.SVGElement = savedSvgElement;
        globalThis.SVGAnimatedString = savedSvgAnimatedString;

        dom.window.close();
    }
}

/**
 * Is this value the same after a JSON round trip?
 *
 * The state island is JSON, and the client *replaces* a component's own `data()` values
 * with what it finds there. So a value that JSON cannot carry does not merely lose detail
 * on the way — it arrives as something else and is used in place of the real thing.
 *
 * The case that shows it is `@pwaxImport`. A component held in `data()` is a Vue async
 * component: a plain object whose meaning is entirely in its `setup` and `__asyncLoader`
 * functions. `JSON.stringify` drops functions, so the island carried
 * `{"name":"AsyncComponentWrapper","__asyncResolved":{…}}` — and on hydration
 * `<component :is="badge">` was handed that object, rendered nothing, and Vue reported a
 * mismatch against the server's markup, which had rendered the real component. The
 * sub-component was simply missing from the page, with an error in the console and nothing
 * pointing at the cause.
 *
 * A `Date`, a `Map`, a `Set`, a class instance and a cycle all fail the same way. None of
 * them is state the client cannot rebuild for itself — `data()` runs on the client too —
 * so the right answer is to leave the key out and let it.
 *
 * `undefined` is the one exception, and is treated as absent rather than unsafe: JSON drops
 * such a property, the client's own `data()` supplies it again on the merge, and objects
 * carrying an optional field that happens to be unset are ordinary.
 */
function jsonSafe(value, seen = new Set()) {
    if (value === null) {
        return true;
    }

    const type = typeof value;

    if (type === 'string' || type === 'boolean') {
        return true;
    }

    if (type === 'number') {
        // `NaN` and the infinities serialize to `null`, which is a different value.
        return Number.isFinite(value);
    }

    if (type !== 'object') {
        return false;
    }

    // A cycle throws in `JSON.stringify`, so it can never round-trip.
    if (seen.has(value)) {
        return false;
    }

    seen.add(value);

    if (Array.isArray(value)) {
        return value.every((item) => jsonSafe(item, seen));
    }

    const proto = Object.getPrototypeOf(value);

    // Anything with a prototype of its own — a Date, a Map, a Set, a class instance, a
    // RegExp — comes back as something else, or as `{}`.
    if (proto !== Object.prototype && proto !== null) {
        return false;
    }

    return Object.values(value).every((item) => item === undefined || jsonSafe(item, seen));
}

/**
 * The subset of the captured state the client may safely be seeded with.
 *
 * Keys are dropped whole rather than repaired. A partially-restored value is worse than an
 * absent one: absent means the client's own `data()` result stands, which is the value the
 * server started from too.
 *
 * The names of the dropped keys travel back with the result so the PHP side can say so in
 * debug mode. Without that the symptom — one sub-component missing, one line in the console
 * about a hydration mismatch — points nowhere near `data()`.
 */
function seedable(captured, fallback) {
    let source = captured;

    if (!source || typeof source !== 'object' || Array.isArray(source)) {
        source = fallback && typeof fallback === 'object' ? fallback : {};
    }

    const state = {};
    const unseeded = [];

    for (const [key, value] of Object.entries(source)) {
        if (value === undefined) {
            continue;
        }

        if (jsonSafe(value)) {
            state[key] = value;

            continue;
        }

        unseeded.push(key);
    }

    try {
        // Belt and braces: everything above should already survive this, and a state island
        // that cannot be written at all is worse than one that seeds nothing.
        return { state: JSON.parse(JSON.stringify(state)), unseeded };
    } catch {
        return { state: {}, unseeded };
    }
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
