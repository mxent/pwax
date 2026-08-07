/**
 * Build the pwax client runtime.
 *
 * Produces `dist/pwax.js` (+ source map), which is committed to the repository and
 * shipped inside the Composer package. Consumers never run this — CI verifies that the
 * committed bundle matches the sources with `npm run build && git diff --exit-code dist/`.
 */
import { build, context } from 'esbuild';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = dirname(fileURLToPath(import.meta.url));
const pkg = JSON.parse(readFileSync(resolve(root, 'package.json'), 'utf8'));
const watch = process.argv.includes('--watch');

/** @type {import('esbuild').BuildOptions} */
const options = {
    entryPoints: [resolve(root, 'src/js/index.js')],
    outfile: resolve(root, 'dist/pwax.js'),
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
};

if (watch) {
    const ctx = await context(options);
    await ctx.watch();
    console.log('pwax: watching src/js …');
} else {
    await build(options);
}
