/**
 * End to end, with nothing stubbed: the real compiler, the real wrapper, the real
 * runtime-only Vue build this package vendors.
 *
 * Everything else about precompiling can be checked by looking at strings — does the
 * module carry `__pwaxRender`, does the shell ask for the smaller Vue. None of that proves
 * the one thing that matters, which is that the source `pwax:compile` writes can be
 * evaluated by the Vue that gets served and produce the right DOM. Three separate choices
 * have to agree for that to be true — `mode: 'function'`, `prefixIdentifiers`,
 * `runtimeGlobalName` — and each of them fails at render time in the browser, on a page
 * that is simply blank.
 *
 * The compiler runs as a subprocess through `bin/compile-templates.mjs`, the same way the
 * Artisan command runs it, so the options under test are the ones that ship.
 */
import { afterEach, describe, expect, it, beforeAll } from 'vitest';
import { ensureRenderable } from '../../src/js/modules.js';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const require = createRequire(import.meta.url);
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/** Run the real compiler script over a batch, exactly as `pwax:compile` does. */
function compile(templates, version) {
    const out = execFileSync('node', [`${root}/bin/compile-templates.mjs`], {
        input: JSON.stringify({ version, templates }),
        encoding: 'utf8',
    });

    return JSON.parse(out);
}

/**
 * The wrapper `RenderFunctionStore::bindings()` emits, evaluated.
 *
 * Kept in step with the PHP by hand, which is the point of asserting on it: if the shape
 * there changes and this does not, this test is what says the emitted module is broken.
 */
function toRenderFunction(code, Vue) {
    return new Function('Vue', `return (() => {\n${code}\n})();`)(Vue);
}

describe('compiled render functions run on the vendored runtime build', () => {
    let Vue;

    beforeAll(() => {
        const build = `${root}/resources/vendor/vue.runtime.global.prod.js`;

        expect(existsSync(build)).toBe(true);

        // The IIFE global build assigns `var Vue = …`, so evaluating it in a function body
        // and returning that binding hands back the whole runtime. jsdom supplies the
        // `document` it reaches for.
        Vue = new Function(`${readFileSync(build, 'utf8')}\n; return Vue;`)();

        // The build under test really has no compiler. Note what that looks like: the
        // property is *present*, as a stub returning nothing. Testing `typeof Vue.compile`
        // therefore says "yes, there is a compiler" on the one build where there is not.
        expect(typeof Vue.compile).toBe('function');
        expect(Vue.compile('<i></i>')).toBeUndefined();
    });

    it('renders reactive state, a list and an event handler', () => {
        const version = require('@vue/compiler-dom/package.json').version;

        const template = `
            <div class="card" data-v-abc123>
                <h1>{{ title }}</h1>
                <ul><li v-for="item in items" :key="item">{{ item }}</li></ul>
                <button type="button" @click="count++">{{ count }}</button>
            </div>
        `;

        const result = compile({ card: template }, version);

        expect(result.ok).toBe(true);
        expect(result.errors).toEqual({});
        expect(result.version).toBe(version);

        const host = document.createElement('div');
        document.body.appendChild(host);

        Vue.createApp({
            render: toRenderFunction(result.functions.card, Vue),
            data: () => ({ title: 'Hello', items: ['a', 'b'], count: 0 }),
        }).mount(host);

        expect(host.querySelector('h1').textContent).toBe('Hello');
        expect(host.querySelectorAll('li')).toHaveLength(2);

        // The scope attribute the server stamps onto the template survives compilation,
        // which is what keeps scoped styles applying under this mode.
        expect(host.querySelector('.card').hasAttribute('data-v-abc123')).toBe(true);

        host.querySelector('button').click();

        return Vue.nextTick().then(() => {
            expect(host.querySelector('button').textContent).toBe('1');
        });
    });

    /**
     * A precompiled page has to render the same DOM as one compiled in the browser.
     *
     * `@vue/compiler-dom`'s Node build hardwires `transformHoist: stringifyStatic`, which
     * the browser build sets to `null`. Past a threshold — five elements carrying bindings
     * is enough — it replaces a hoisted static subtree with an HTML string the compiler
     * writes itself and the runtime mounts by assigning `innerHTML`, so those elements are
     * never built node by node.
     *
     * The two disagree on the attributes whose presence is their value: the compiler writes
     * `:required="true"` as `required="true"`, and an element the runtime builds carries
     * `required=""`. So running `pwax:compile` silently changed the markup of any page big
     * enough to cross the threshold — and, with SSR on, changed it away from what the server
     * had sent.
     */
    it("builds the same DOM as the browser's own compiler", () => {
        const template =
            '<div>' +
            '<input id="a" type="text" :required="true" :readonly="false">' +
            '<input id="b" type="checkbox" :checked="true">' +
            '<details :open="true"><summary>s</summary></details>' +
            '<video :controls="true" :loop="false"></video>' +
            '<button :disabled="false" :aria-expanded="false">b</button>' +
            '<p :aria-hidden="false">t</p>' +
            '</div>';

        const { functions } = compile({ big: template });

        const host = document.createElement('div');
        document.body.appendChild(host);

        Vue.createApp({ render: toRenderFunction(functions.big, Vue) }).mount(host);

        expect(host.querySelector('#a').getAttribute('required')).toBe('');
        expect(host.querySelector('#a').hasAttribute('readonly')).toBe(false);
        expect(host.querySelector('details').getAttribute('open')).toBe('');
        expect(host.querySelector('video').getAttribute('controls')).toBe('');
        expect(host.querySelector('video').hasAttribute('loop')).toBe(false);
        expect(host.querySelector('button').hasAttribute('disabled')).toBe(false);

        // And `false` stays `false` where the attribute is an ordinary one.
        expect(host.querySelector('button').getAttribute('aria-expanded')).toBe('false');
        expect(host.querySelector('p').getAttribute('aria-hidden')).toBe('false');
    });

    /**
     * `prefixIdentifiers` is what turns `msg` into `_ctx.msg`. Without it the compiler
     * emits `with (_ctx) { … }`, which is a SyntaxError in strict mode — and every
     * component Pwax serves is a module, which is always strict.
     */
    it('emits no `with` block', () => {
        const { functions } = compile({ a: '<p>{{ msg }}</p>' });

        expect(functions.a).not.toContain('with (');
        expect(functions.a).toContain('_ctx.msg');
        expect(() => new Function(`"use strict";\n${functions.a}`)).not.toThrow();
    });

    it('refuses a compiler that does not match the shipped runtime', () => {
        const result = compile({ a: '<p></p>' }, '2.7.16');

        expect(result.ok).toBe(false);
        expect(result.message).toMatch(/does not match/);
    });

    /**
     * One broken template must not fail the batch. The command reports it by hash and
     * exits non-zero; the rest of the application still gets its render functions.
     */
    it('reports a template that will not compile without losing the others', () => {
        const result = compile({ good: '<p>ok</p>', bad: '<p v-for="">' });

        expect(result.ok).toBe(true);
        expect(result.functions.good).toBeTruthy();
        expect(Object.keys(result.errors)).toEqual(['bad']);
    });
});

/**
 * The guard, against the two real builds rather than against a stub.
 *
 * Both define `compile`, so the obvious test for it — `typeof Vue.compile === 'function'` —
 * passes on the build that cannot compile anything and the guard never fires. A stubbed
 * Vue would happily agree with whichever discriminator was written; only the shipped files
 * can say which one is right.
 */
describe('ensureRenderable against the vendored builds', () => {
    const load = (file) =>
        new Function(
            `${readFileSync(`${root}/resources/vendor/${file}`, 'utf8')}\n; return Vue;`
        )();

    afterEach(() => {
        delete globalThis.Vue;
    });

    it('accepts a template on the full build', () => {
        globalThis.Vue = load('vue.global.prod.js');

        expect(() => ensureRenderable({ template: '<p>hi</p>' })).not.toThrow();
    });

    it('refuses a template on the runtime-only build', () => {
        globalThis.Vue = load('vue.runtime.global.prod.js');

        expect(() => ensureRenderable({ template: '<p>hi</p>' })).toThrow(/pwax:compile/);
    });

    it('accepts a render function on the runtime-only build', () => {
        globalThis.Vue = load('vue.runtime.global.prod.js');

        expect(() => ensureRenderable({ render: () => null })).not.toThrow();
    });
});
