/**
 * Build the pwax client runtime and service worker.
 *
 * Produces `dist/pwax.js` and `dist/pwax-sw.js` (+ source maps), both committed to the
 * repository and shipped inside the Composer package. Consumers never run this — CI
 * verifies the committed bundles match the sources with
 * `npm run build && git diff --exit-code dist/`.
 *
 * Two entry points, one set of options. The worker is built exactly like the runtime
 * because it is exactly as much production JavaScript; until 4.1 it lived in a Blade
 * template and was never linted, formatted or minified.
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
    // Matches the browsers Vue 3.5 itself supports.
    target: ['es2020', 'chrome87', 'firefox78', 'safari14', 'edge88'],
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

export const targets = [
    bundle('src/js/index.js', 'dist/pwax.js'),
    bundle('src/js/sw/index.js', 'dist/pwax-sw.js'),
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
