/**
 * Build the pwax client runtime, service worker and JSON renderer.
 *
 * Produces `dist/pwax.js`, `dist/pwax-sw.js` and `dist/pwax-json.js` (+ source maps),
 * all committed to the repository and shipped inside the Composer package. Consumers
 * never run this — CI verifies the committed bundles match the sources with
 * `npm run build && git diff --exit-code dist/`.
 *
 * Three entry points, one set of options. The worker is built exactly like the runtime
 * because it is exactly as much production JavaScript; the JSON renderer adds only the
 * `vue` alias described below, because it is the one bundle with npm dependencies in it.
 */
import { build, context } from 'esbuild';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = dirname(fileURLToPath(import.meta.url));
const pkg = JSON.parse(readFileSync(resolve(root, 'package.json'), 'utf8'));
const watch = process.argv.includes('--watch');

/**
 * @param {string} entry
 * @param {string} outfile
 * @returns {import('esbuild').BuildOptions}
 */
const bundle = (entry, outfile) => ({
    entryPoints: [resolve(root, entry)],
    outfile: resolve(root, outfile),
    bundle: true,
    format: 'iife',
    platform: 'browser',
    // Matches the browsers Vue 3.5 itself supports, with one adjustment: Safari 14.0
    // mis-parses destructuring in some positions, so the floor is the 14.1 that fixed it.
    target: ['es2020', 'chrome87', 'firefox78', 'safari14.1', 'edge88'],
    minify: !watch,
    sourcemap: true,
    legalComments: 'none',
    banner: {
        js: `/*! pwax v${pkg.version} | MIT | https://github.com/mxent/pwax */`,
    },
    define: {
        __PWAX_VERSION__: JSON.stringify(pkg.version),
    },
    logLevel: 'info',
});

/**
 * Resolve `vue` to the global build Pwax already serves.
 *
 * Pwax serves `vue.global.prod.js` as a script tag; nothing on the page imports Vue as
 * a module. Bundling `@json-render/vue`'s peer import would inline a second Vue — two
 * runtimes, two reactivity systems, and components from one that the other cannot
 * mount — so it is aliased to a file that re-exports the global instead.
 *
 * Nothing here checks that the shim is complete, because esbuild already does: an
 * import the shim does not re-export fails the build with `No matching export in
 * "src/js/json/vue-global.js" for import "…"`, naming the file to edit and the name to
 * add. That is the tripwire for a json-render upgrade reaching for a Vue API the shim
 * has never had to cover.
 *
 * @returns {import('esbuild').Plugin}
 */
const vueAlias = () => ({
    name: 'pwax-vue-global',
    setup(build) {
        const shim = resolve(root, 'src/js/json/vue-global.js');

        build.onResolve({ filter: /^vue$/ }, (args) =>
            // The shim imports nothing, so it can never be its own importer — but
            // resolving it to itself would recurse if it ever did.
            args.importer === shim ? null : { path: shim }
        );
    },
});

export const targets = [
    bundle('src/js/index.js', 'dist/pwax.js'),
    bundle('src/js/sw/index.js', 'dist/pwax-sw.js'),
    {
        ...bundle('src/js/json/index.js', 'dist/pwax-json.js'),
        // Published as a global rather than imported, for the same reason the runtime
        // is: `src/js/json.js` loads this with a script tag so it can carry the CSP
        // nonce, and a script tag has no import bindings.
        globalName: 'PwaxJson',
        plugins: [vueAlias()],
    },
];

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    if (watch) {
        for (const options of targets) {
            const ctx = await context(options);
            await ctx.watch();
        }

        console.log('pwax: watching src/js …');
    } else {
        await Promise.all(targets.map((options) => build(options)));
    }
}
