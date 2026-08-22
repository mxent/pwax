#!/usr/bin/env node
/**
 * Compile Pwax templates to Vue render functions.
 *
 * Run by `php artisan pwax:compile`, once for the whole batch rather than once per
 * template — starting Node is the expensive part, and an application with two hundred
 * components would otherwise start it two hundred times.
 *
 * Reads `{"version": "3.5.41", "templates": {"<hash>": "<template>"}}` on stdin and writes
 * `{"ok": true, "functions": {"<hash>": "<source>"}, "errors": {...}}` on stdout. Nothing
 * else goes to stdout; diagnostics go to stderr, so a stray console.log cannot corrupt the
 * result the PHP side parses.
 *
 * `@vue/compiler-dom` is an optional peer dependency. It is not needed to use Pwax — the
 * default path compiles templates in the browser and requires no Node at all — so it is
 * installed only by applications that opt into precompiling:
 *
 *     npm install --save-dev @vue/compiler-dom@<the version Pwax vendors>
 */

import { createRequire } from 'node:module';

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
    fail(`Could not read the batch from stdin: ${error.message}`);
}

const wanted = typeof input.version === 'string' ? input.version : '';

const install = `\`npm install --save-dev @vue/compiler-dom@${wanted || 'latest'}\``;

let compiler;

try {
    compiler = await import('@vue/compiler-dom');
} catch {
    fail(
        'The optional peer dependency @vue/compiler-dom is not installed. Run ' +
            `${install} in your application, or set pwax.assets.vue_build back to "full".`
    );
}

/*
 * The compiler and the shipped Vue runtime must be the same version.
 *
 * A mismatched compiler emits render functions the runtime cannot execute, and it fails at
 * render time in the browser with an error that names neither — which is a very long
 * afternoon. Refusing here costs one clear message instead.
 *
 * Read from the package manifest, not from the module: `@vue/compiler-dom` does not export
 * a `version` binding, so comparing `compiler.version` compared `undefined` and this guard
 * never once fired.
 */
let installed = '';

try {
    installed = require('@vue/compiler-dom/package.json').version || '';
} catch {
    // Resolvable as a module but not as a manifest — an unusual bundling, not a reason to
    // refuse. Skipping the comparison is the same position as not knowing the version.
}

if (wanted && installed && installed !== wanted) {
    fail(
        `@vue/compiler-dom ${installed} does not match the Vue runtime Pwax ships ` +
            `(${wanted}). Install the matching version: ${install}.`
    );
}

const functions = {};
const errors = {};

for (const [hash, template] of Object.entries(input.templates || {})) {
    try {
        const { code } = compiler.compile(template, {
            // A standalone function expression the module can export, rather than an ES
            // module the browser would have to import separately. The generated chunk ends
            // in `return function render(…)`, so evaluating it yields the function.
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
            // Not optional. Without it the compiler emits `with (_ctx) { … }`, which is a
            // SyntaxError in a module — and every component Pwax serves is a module.
            prefixIdentifiers: true,
            // The runtime build exposes its helpers on the global `Vue`, which is exactly
            // how they are reachable from a component module served by Pwax.
            runtimeGlobalName: 'Vue',
            // Pinned rather than left to default, which follows whether the compiler is a
            // development build. A render function that keeps a template's HTML comments
            // does not match one that drops them, and both this and `bin/ssr.mjs` have to
            // agree with the production runtime the browser is served.
            comments: false,
        });

        functions[hash] = code;
    } catch (error) {
        errors[hash] = error && error.message ? error.message : String(error);
    }
}

write({ ok: true, version: installed, functions, errors });
