/**
 * End to end, with nothing stubbed: the real `@vue/server-renderer`, the real
 * `@vue/compiler-dom`, and the real Vue runtime this package vendors — invoked through
 * `bin/ssr.mjs` exactly as the PHP `Prerenderer` invokes it.
 *
 * These assert the *shape* of the emitted HTML, which is not cosmetic. The client's page
 * component is a fragment, so Vue brackets its output with `<!--[-->` / `<!--]-->` and
 * leaves a `<!---->` where the loader branch did not render. Hydration compares those
 * nodes; HTML without them is discarded and the page is drawn again on the client. The
 * bridge's first version emitted exactly that, and every prerender was silently wasted.
 *
 * `ssrHydration.test.js` asserts the other half — that the runtime actually adopts this
 * markup. The two together are what say SSR works.
 */
import { describe, expect, it } from 'vitest';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { copyFileSync, mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const require = createRequire(import.meta.url);

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const TEMPLATES = {
    content: '<main><router-view></router-view></main>',
    loader: '<div class="pwax-loading" role="status">Loading…</div>',
    error: '<div class="pwax-screen pwax-error" role="alert"></div>',
};

/** Run the real SSR script over a payload, exactly as `Prerenderer` does. */
function render(payload) {
    const out = execFileSync('node', [`${root}/bin/ssr.mjs`], {
        input: JSON.stringify({ version: '3.5.41', templates: TEMPLATES, ...payload }),
        encoding: 'utf8',
    });

    return JSON.parse(out);
}

/** The page component's markup, wrapped as the client's virtual DOM will be. */
const wrapped = (html) => `<main><!--[--><!---->${html}<!--]--></main>`;

describe('bin/ssr.mjs renders a component to HTML', () => {
    it('renders a data() component with its reactive state', () => {
        const result = render({
            component: {
                template: '<div class="home"><h1>{{ title }}</h1><p>{{ count }}</p></div>',
                script: 'export default { data() { return { title: "Home", count: 3 }; } };',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);
        expect(result.html).toBe(wrapped('<div class="home"><h1>Home</h1><p>3</p></div>'));
        expect(result.serializedState).toEqual({ title: 'Home', count: 3 });
    });

    it('renders controller data the way Blade delivers it', () => {
        // A Pwax component is a Blade view, so `pwaxRender('pages.x', ['name' => 'Ada'])`
        // has already interpolated the data into the template and the script before either
        // reaches this script — see `tests/fixtures/views/pages/with-data.blade.php`. The
        // bridge used to *also* pass the data as props, which is not how the browser
        // renders the same component, so the two disagreed on every such page.
        const result = render({
            component: {
                template: '<div class="greeting">Hello {{ name }}</div>',
                script: 'export default { data() { return { name: "Ada" }; } };',
                style: '',
                scope: null,
            },
            data: { name: 'Ada' },
        });

        expect(result.ok).toBe(true);
        expect(result.html).toBe(wrapped('<div class="greeting">Hello Ada</div>'));
        expect(result.serializedState).toEqual({ name: 'Ada' });
    });

    it('brackets the page in the anchors the client fragment produces', () => {
        const result = render({
            component: { template: '<p>Anchored</p>', script: '', style: '', scope: null },
            data: {},
        });

        expect(result.ok).toBe(true);

        // Spelled out rather than left to `wrapped()`: this is the assertion the whole
        // feature rests on, and it should be readable without following a helper.
        expect(result.html).toBe('<main><!--[--><!----><p>Anchored</p><!--]--></main>');
    });

    it('renders router-links as anchors, with the active classes Vue Router applies', () => {
        const result = render({
            url: '/about',
            component: {
                template:
                    '<nav><router-link to="/">Home</router-link>' +
                    '<router-link to="/about">About</router-link></nav>',
                script: '',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);
        expect(result.html).toContain('<a href="/"');
        expect(result.html).toContain('router-link-active router-link-exact-active');
    });

    it('renders a precompiled component, the way pwax:compile ships one', () => {
        // `Pwax::payload()` prepends `RenderFunctionStore::bindings()` to a page's inline
        // script when `php artisan pwax:compile` has run. The body `@vue/compiler-dom`
        // generates opens with `const { … } = Vue` and is immediately invoked, so the global
        // is dereferenced as the module is imported — not when the component renders.
        //
        // Node's module scope has no such global. Every prerender of a precompiled
        // application therefore failed with `Vue is not defined` and fell back to the SPA
        // shell, and precompiling is the recommended setup for the smaller Vue build.
        const template = '<div class="home"><h1>{{ title }}</h1></div>';
        const { code } = require('@vue/compiler-dom').compile(template, {
            mode: 'function',
            hoistStatic: true,
            prefixIdentifiers: true,
            runtimeGlobalName: 'Vue',
        });

        const result = render({
            component: {
                template,
                script:
                    `const __pwaxRender = (() => {\n${code}\n})();\nexport { __pwaxRender };\n` +
                    'export default { data() { return { title: "Home" }; } };',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);

        // Byte for byte what the same component produces without a precompiled render — the
        // bridge prefers the shipped function, and it has to agree with the template.
        expect(result.html).toBe(wrapped('<div class="home"><h1>Home</h1></div>'));
    });

    it('compiles the Blade template when the script declares no render or template', () => {
        const result = render({
            component: {
                template: '<section><h2>{{ heading }}</h2></section>',
                script: 'export default { data() { return { heading: "Welcome" }; } };',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);
        expect(result.html).toContain('<h2>Welcome</h2>');
    });

    it('reports a failure rather than throwing when the template is invalid', () => {
        const result = render({
            component: { template: '<div><if-broken', script: '', style: '', scope: null },
            data: {},
        });

        expect(result.ok).toBe(false);
        expect(typeof result.message).toBe('string');
        expect(result.message.length).toBeGreaterThan(0);
    });

    it('reports a missing dependency as JSON, naming all three and the version', () => {
        // The failure this covers is the one that shipped: `vue` was imported at the top of
        // the module, outside every guard, so an application that installed only the two
        // documented packages got an unhandled ERR_MODULE_NOT_FOUND — Node died with
        // nothing on stdout, and the PHP side could only report "Node exited with 1".
        //
        // Node resolves bare specifiers by walking up from the *script*, so neither `cwd`
        // nor `NODE_PATH` can hide this repository's `node_modules` from it. A copy of the
        // bridge under the system temp directory can — it keeps the same relative layout so
        // the one import that must not be optional still resolves.
        const sandbox = mkdtempSync(join(tmpdir(), 'pwax-ssr-'));

        mkdirSync(join(sandbox, 'bin'));
        mkdirSync(join(sandbox, 'src', 'js'), { recursive: true });
        copyFileSync(`${root}/bin/ssr.mjs`, join(sandbox, 'bin', 'ssr.mjs'));
        copyFileSync(
            `${root}/src/js/pageTemplate.mjs`,
            join(sandbox, 'src', 'js', 'pageTemplate.mjs')
        );

        // A non-zero exit would make `execFileSync` throw, so the crash this guards against
        // fails the test rather than passing quietly.
        const out = execFileSync('node', [join(sandbox, 'bin', 'ssr.mjs')], {
            input: JSON.stringify({
                version: '3.5.41',
                component: { template: '<div></div>' },
                data: {},
            }),
            encoding: 'utf8',
        });

        rmSync(sandbox, { recursive: true, force: true });

        const result = JSON.parse(out);

        expect(result.ok).toBe(false);
        expect(result.message).toMatch(/not installed/);
        expect(result.message).toContain('npm install --save-dev vue@3.5.41');
        expect(result.message).toContain('@vue/server-renderer@3.5.41');
        expect(result.message).toContain('@vue/compiler-dom@3.5.41');
    });
});
