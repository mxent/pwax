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

    it('resolves a sub-component imported with @pwaxImport', () => {
        const url = '/__pwax__/c/modal.js';

        const result = render({
            component: {
                template: '<div class="home"><Modal /></div>',
                script: `export default { components: { Modal: window.pwax.component(${JSON.stringify(url)}, "") } };`,
                style: '',
                scope: null,
            },
            data: {},
            imports: {
                [url]: {
                    template: '<aside class="modal">Body</aside>',
                    script: 'export default { name: "Modal" };',
                },
            },
        });

        expect(result.ok).toBe(true);
        expect(result.html).toBe(
            wrapped('<div class="home"><aside class="modal">Body</aside></div>')
        );
    });

    it('resolves a named export, as `X from view` imports one', () => {
        const url = '/__pwax__/c/badge.js';

        const result = render({
            component: {
                template: '<div><Pill /></div>',
                script: `export default { components: { Pill: window.pwax.component(${JSON.stringify(url)}, "Pill") } };`,
                style: '',
                scope: null,
            },
            data: {},
            imports: {
                [url]: {
                    template: '<span class="badge"></span>',
                    script:
                        'export default { name: "Badge" };\n' +
                        'export const Pill = { name: "Pill", template: "<em>Pill</em>" };',
                },
            },
        });

        expect(result.ok).toBe(true);
        expect(result.html).toBe(wrapped('<div><em>Pill</em></div>'));
    });

    it('resolves components that import each other', () => {
        // Two components referencing each other is the arrangement `Pwax::import()` was
        // designed around, and the reason the emitted call returns an async component
        // rather than resolving on the spot. Evaluating eagerly here would recurse until
        // the stack ran out.
        const a = '/__pwax__/c/a.js';
        const b = '/__pwax__/c/b.js';

        const result = render({
            component: {
                template: '<div><A /></div>',
                script: `export default { components: { A: window.pwax.component(${JSON.stringify(a)}, "") } };`,
                style: '',
                scope: null,
            },
            data: {},
            imports: {
                [a]: {
                    template: '<p class="a"><B v-if="depth" :depth="0" /></p>',
                    script: `export default { props: { depth: { default: 1 } }, components: { B: window.pwax.component(${JSON.stringify(b)}, "") } };`,
                },
                [b]: {
                    template: '<span class="b"><A v-if="depth" :depth="0" /></span>',
                    script: `export default { props: { depth: { default: 1 } }, components: { A: window.pwax.component(${JSON.stringify(a)}, "") } };`,
                },
            },
        });

        expect(result.ok).toBe(true);
        expect(result.html).toContain('<p class="a"><span class="b">');
    });

    it('fails rather than emitting markup the browser will not agree with', () => {
        // An unresolved component renders as a literal element of that name and an
        // unresolved directive is silently dropped — markup that looks plausible, ships,
        // gets indexed, and is thrown away the moment the browser hydrates it. Failing
        // means the SPA shell is served instead, which is merely slower.
        const unresolved = render({
            component: {
                template: '<div><MysteryThing /></div>',
                script: '',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(unresolved.ok).toBe(false);
        expect(unresolved.message).toContain('MysteryThing');
        expect(unresolved.message).toContain('spaOnly()');

        const directive = render({
            component: {
                template: '<div v-tooltip="1">x</div>',
                script: '',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(directive.ok).toBe(false);
        expect(directive.message).toContain('tooltip');
    });

    it('drops template comments, as the production runtime compiler does', () => {
        // `comments` defaults to whether the compiler is a development build, and this
        // bridge deliberately runs the development one so Vue's resolution warnings exist.
        // Left to that default the server kept every `<!-- … -->` in a template while the
        // browser — compiling the same template with the production runtime Pwax ships —
        // dropped them, so any component containing a comment hydrated with a mismatch.
        const result = render({
            component: {
                template: '<div class="c"><!-- a note --><p>After</p></div>',
                script: '',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);
        expect(result.html).toBe(wrapped('<div class="c"><p>After</p></div>'));
        expect(result.html).not.toContain('a note');
    });

    it('leaves a native custom element alone', () => {
        // A hyphenated tag Vue cannot resolve is rendered as a literal element of that
        // name — here and in the browser alike, since Pwax does not set `isCustomElement`.
        // The markup agrees, so there is nothing to refuse, and refusing would have blocked
        // every application that uses a web component from prerendering at all.
        const result = render({
            component: {
                template: '<div><my-widget>x</my-widget></div>',
                script: '',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);
        expect(result.html).toBe(wrapped('<div><my-widget>x</my-widget></div>'));
    });

    it('refuses a page whose content is teleported out of the mount element', () => {
        // `<Teleport>` renders its children somewhere else in the document, and the server
        // renderer collects them separately rather than putting them in the returned
        // string. Placing them would mean knowing every target in advance, which is not
        // possible — `to` is a selector and it can be computed. So the markup would ship
        // without the modal, dropdown or dialog that was the point of prerendering it.
        const result = render({
            component: {
                template: '<div><Teleport to="body"><p>Modal</p></Teleport></div>',
                script: '',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(false);
        expect(result.message).toContain('Teleport');
        expect(result.message).toContain('spaOnly()');
    });

    it('is unbothered by a teleport that renders nothing', () => {
        // The common shape: a modal that is closed. Nothing is teleported, so nothing is
        // missing from the markup, so there is nothing to refuse.
        const result = render({
            component: {
                template:
                    '<div class="a"><Teleport v-if="open" to="body"><p>Modal</p></Teleport></div>',
                script: 'export default { data() { return { open: false }; } };',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);
        expect(result.html).toContain('<div class="a">');
    });

    it('names the browser API a component reached for, and what to do instead', () => {
        const result = render({
            component: {
                template: '<p>{{ t }}</p>',
                script: 'export default { data() { return { t: document.title }; } };',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(false);
        expect(result.message).toContain('`document` is not available while prerendering');
        expect(result.message).toContain('mounted()');
        expect(result.message).toContain('spaOnly()');
    });

    it('leaves `typeof window === "undefined"` telling the truth', () => {
        // The obvious repair for the above — declaring a `window` global — would make this
        // check take the browser branch and read `window.innerWidth` as `undefined`: no
        // error, a plausible value, and markup that disagrees with the browser's.
        const result = render({
            component: {
                template: '<p>{{ server }}</p>',
                script: 'export default { data() { return { server: typeof window === "undefined" }; } };',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);
        expect(result.html).toBe(wrapped('<p>true</p>'));
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

describe('bin/ssr.mjs settle mode (post-mount rendering)', () => {
    it('captures data populated in mounted()', () => {
        // The standard renderToString path produces an empty list — mounted() has not
        // run. Settle mode mounts to a jsdom document, lets mounted() fire, and waits
        // for the DOM to update.
        const result = render({
            settle: true,
            settleDelay: 50,
            component: {
                template:
                    '<div><ul><li v-for="item in items" :key="item">{{ item }}</li></ul></div>',
                script: 'export default { data() { return { items: [] }; }, mounted() { this.items = ["Apple", "Banana", "Cherry"]; } };',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);
        expect(result.html).toContain('<li>Apple</li>');
        expect(result.html).toContain('<li>Banana</li>');
        expect(result.html).toContain('<li>Cherry</li>');
        // Settle mode returns hydrate: false — the client re-renders, not hydrates.
        expect(result.hydrate).toBe(false);
    });

    it('captures styles injected into <head> during mounted()', () => {
        // The Tailwind CDN JS injects a <style> tag into <head> at mount time. The
        // settle path captures those and returns them as headStyles.
        const result = render({
            settle: true,
            settleDelay: 50,
            component: {
                template: '<div class="home"><h1>{{ title }}</h1></div>',
                script: 'export default { data() { return { title: "Home" }; }, mounted() { const s = document.createElement("style"); s.textContent = ".home { color: red; }"; document.head.appendChild(s); } };',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);
        expect(result.headStyles).toBeDefined();
        expect(result.headStyles.length).toBeGreaterThan(0);
        expect(result.headStyles.some((s) => s.includes('.home { color: red; }'))).toBe(true);
    });

    it('does not hydrate (returns hydrate: false)', () => {
        const result = render({
            settle: true,
            settleDelay: 10,
            component: {
                template: '<div>{{ msg }}</div>',
                script: 'export default { data() { return { msg: "Hi" }; } };',
                style: '',
                scope: null,
            },
            data: {},
        });

        expect(result.ok).toBe(true);
        expect(result.hydrate).toBe(false);
    });

    it('the standard path is unaffected when settle is false', () => {
        const result = render({
            component: {
                template:
                    '<div><ul><li v-for="item in items" :key="item">{{ item }}</li></ul></div>',
                script: 'export default { data() { return { items: [] }; }, mounted() { this.items = ["Apple"]; } };',
                style: '',
                scope: null,
            },
            data: {},
        });

        // The standard path does NOT run mounted(), so the list stays empty.
        expect(result.ok).toBe(true);
        expect(result.html).not.toContain('<li>Apple</li>');
        // No hydrate flag means the default (true) — the client hydrates.
        expect(result.hydrate).toBeUndefined();
    });
});
