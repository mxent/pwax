/**
 * Build the service worker once, before the suite runs.
 *
 * Into memory rather than from `dist/`, so no test can pass against a bundle that is
 * stale relative to `src/js/sw/`. CI still checks the committed `dist/` separately with
 * `npm run build && git diff --exit-code dist/` — that catches a build nobody committed;
 * this catches a build nobody made.
 *
 * Unminified, because a stack trace out of the sandbox should name the function that
 * threw. What is served in production is minified, and the two differ only in names.
 */
import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));

export default async function setup({ provide }) {
    const result = await build({
        entryPoints: [resolve(HERE, '../../../src/js/sw/index.js')],
        bundle: true,
        format: 'iife',
        platform: 'browser',
        target: ['es2020'],
        minify: false,
        write: false,
        logLevel: 'silent',
    });

    provide('pwaxWorkerBundle', result.outputFiles[0].text);
}
