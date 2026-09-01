/**
 * Build the JSON renderer once, before the suite runs.
 *
 * Into memory rather than from `dist/`, for the same reason the service worker is built
 * that way: a test that reads `dist/pwax-json.js` passes against whatever was last
 * committed, which is exactly the bundle a change to `src/js/json/` has not reached.
 *
 * The options come from `build.js` rather than being restated, so the `vue` alias —
 * the one thing that makes this bundle different from every other — is the same one
 * production gets. Unminified, so a stack trace out of jsdom names the function that
 * threw; the two builds differ only in names.
 */
import { build } from 'esbuild';
import { targets } from '../../../build.js';

export default async function setup({ provide }) {
    const target = targets.find((options) => options.outfile.endsWith('pwax-json.js'));

    if (!target) {
        throw new Error('tests: build.js no longer declares a dist/pwax-json.js target.');
    }

    const result = await build({
        ...target,
        write: false,
        minify: false,
        sourcemap: false,
        logLevel: 'silent',
    });

    provide('pwaxJsonBundle', result.outputFiles[0].text);
}
