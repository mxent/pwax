/**
 * Run the real JSON renderer against the real Vue build.
 *
 * The point of this harness is that nothing here is a stub. `dist/pwax-json.js` bundles
 * `@json-render/vue`, and four of its behaviours are what the runtime's bridge is built
 * around — events reaching actions, `$bindState` writing back, `children` rather than
 * named slots, `repeat` on the container. None of those are documented contracts; they
 * are how version 0.20.0 happens to behave. A stub would assert what we believe rather
 * than what the library does, and would keep passing through the upgrade that changes it.
 *
 * So: the vendored `vue.global.prod.js` — the same file an application is served — and
 * the bundle `globalSetup` just built, both evaluated into the jsdom window the test is
 * already running in.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));

/** @type {string|null} */
let bundle = null;

/** Called from `setup.js` with what `globalSetup` built. */
export function setJsonBundle(source) {
    bundle = source;
}

/**
 * Evaluate Vue and the renderer into this test's globals, once per test file.
 *
 * `globalThis` rather than a `vm` sandbox, unlike the service worker harness: the
 * renderer mounts real components into the jsdom document, so it has to share the
 * document the test is asserting against.
 *
 * @returns {{Vue: any, PwaxJson: any}}
 */
export function loadRenderer() {
    if (!bundle) {
        throw new Error('tests: the JSON bundle was not provided. Is buildJson.js in globalSetup?');
    }

    if (!globalThis.Vue) {
        const vue = readFileSync(
            resolve(HERE, '../../../resources/vendor/vue.global.prod.js'),
            'utf8'
        );

        // Indirect eval, so both scripts see the global scope the way a `<script>` tag
        // would. They are the package's own vendored build and its own bundle — the same
        // two files the browser is served.
        (0, eval)(vue);
    }

    if (!globalThis.PwaxJson) {
        (0, eval)(bundle);
    }

    return { Vue: globalThis.Vue, PwaxJson: globalThis.PwaxJson };
}

/**
 * A stand-in for the runtime's component loader.
 *
 * `load(url)` is all `createRenderer` asks for, and returning plain options rather than
 * fetching a module is what lets a test declare a catalog component inline.
 *
 * @param {Record<string, object>} components options keyed by the URL that resolves them
 */
export function fakeLoader(components) {
    const calls = [];

    return {
        calls,
        load(url) {
            calls.push(url);

            return Object.prototype.hasOwnProperty.call(components, url)
                ? Promise.resolve(components[url])
                : Promise.reject(new Error(`no component at ${url}`));
        },
    };
}

/** Let queued promises and Vue's render queue settle. */
export function settle(ticks = 4) {
    return new Promise((resolve) => setTimeout(resolve, ticks));
}
