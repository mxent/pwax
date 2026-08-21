/**
 * Does a prerendered page actually hydrate?
 *
 * Every other SSR test asserts a *string*: that the bridge produced HTML, that the shell
 * embedded it, that the island is there. None of that says whether the runtime adopts the
 * server's DOM or throws it away, and the answer was "throws it away" for the whole of the
 * feature's first version — three separate faults, each sufficient on its own, and all of
 * them invisible to a test that reads markup.
 *
 * So this one asserts the thing itself, with nothing stubbed: the real `bin/ssr.mjs`, the
 * real vendored Vue and Vue Router, the real runtime entry point. The assertion is node
 * identity. Hydration attaches to the existing element; a mismatch makes Vue discard the
 * subtree and create a new one. Comparing the element *object* before and after mount
 * therefore distinguishes the two even against the production Vue build, which does not
 * warn about mismatches at all.
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/*
 * A page's inline script is evaluated in the browser by `import()`ing a `blob:` URL. Vitest
 * runs modules through Vite's module runner, which resolves specifiers itself and treats a
 * blob URL as a bare package name — so every component with a `<script>` block fails to
 * load here for a reason that has nothing to do with what is under test.
 *
 * Only that one function is replaced, with a direct evaluation of the same source. The rest
 * of the module — `toComponentOptions`, `ensureRenderable`, `styleMetadata` — stays real,
 * because those are the parts that decide what the component options end up being.
 */
vi.mock('../../src/js/modules.js', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        importInlineModule: async (source) => ({
            default: new Function(source.replace(/export\s+default\s+/, 'return '))(),
        }),
    };
});

const CONTENT = '<main><router-view></router-view></main>';
const LOADER = '<div class="pwax-loading" role="status">Loading…</div>';
const ERROR = '<div class="pwax-screen pwax-error" role="alert"></div>';

const TEMPLATES = { content: CONTENT, loader: LOADER, error: ERROR };

/** Run the real SSR bridge over a payload, exactly as `Prerenderer` does. */
function prerender(payload) {
    const out = execFileSync('node', [`${root}/bin/ssr.mjs`], {
        input: JSON.stringify({ version: '3.5.41', templates: TEMPLATES, ...payload }),
        encoding: 'utf8',
    });

    return JSON.parse(out);
}

/** Evaluate one of the vendored IIFE builds and hand back the global it defines. */
function vendored(file, binding) {
    const path = `${root}/resources/vendor/${file}`;

    expect(existsSync(path)).toBe(true);

    return new Function(`${readFileSync(path, 'utf8')}\n; return ${binding};`)();
}

function island(id, contents) {
    const el = document.createElement('script');
    el.type = 'application/json';
    el.id = id;
    el.textContent = contents;
    document.body.appendChild(el);
}

/**
 * Import the runtime and wait for it to reach a known point.
 *
 * `index.js` starts `boot()` on import and does not export it, so the import resolving says
 * only that the module evaluated — not that the application has mounted. `boot()` awaits
 * plugin resolution, the component module, the router and the mount, so the number of
 * microtask turns in between varies with what the page contains. Sleeping a tick and hoping
 * was flaky in about two runs in five, and — worse — the runs that "passed" were reading
 * the server's untouched markup before the runtime had done anything to it, which is the
 * one thing these tests must not mistake for success.
 *
 * `pwax:ready` is dispatched once the application has mounted, which for a prerendered page
 * is the whole story: hydration is synchronous with the mount. A page that was *not*
 * prerendered mounts a loader and fetches in `created()`, so there the signal to wait for
 * is `pwax:navigated`.
 */
async function bootAndWait(event = 'pwax:ready') {
    const settled = new Promise((resolve, reject) => {
        document.addEventListener(event, resolve, { once: true });
        setTimeout(() => reject(new Error(`${event} was never dispatched`)), 5000);
    });

    vi.resetModules();
    await import('../../src/js/index.js');

    await settled;
}

/**
 * Build the document the shell would have served for a prerendered page, then boot.
 *
 * The module cache is reset on each import and the document has to be in place first —
 * the same approach `reboot.test.js` takes.
 */
async function boot({ html, state, component, url = '/', indent = false }) {
    document.head.innerHTML = '';

    // `indent` reproduces a shell someone has reformatted: the markup sits on its own line
    // inside the mount element, so `container.firstChild` is a whitespace text node rather
    // than the page. The shipped shell emits no such whitespace — see the test that asserts
    // it — but the view is publishable, and a mismatch on the container's first node makes
    // Vue render the whole application again *before* the server's markup instead of
    // hydrating it, which shows up as every page rendered twice.
    document.body.innerHTML = indent
        ? `<div id="pwax" tabindex="-1" data-pwax-prerendered>\n        \n            ${html}\n    </div>`
        : `<div id="pwax" tabindex="-1" data-pwax-prerendered>${html}</div>`;

    island('pwax-config', JSON.stringify({ mount: 'pwax', templates: TEMPLATES, pinia: false }));
    island(
        'pwax-initial',
        JSON.stringify({ url, hydrate: true, component: { ...component, hash: 'testhash' } })
    );
    island('pwax-state', JSON.stringify(state));

    await bootAndWait();
}

describe('a prerendered page hydrates rather than being re-rendered', () => {
    /** Everything the runtime and Vue wrote to `console.error` during the current test. */
    let consoleErrors = [];

    /** Vue's own verdict: it recovered from a mismatch rather than hydrating cleanly. */
    const assertNoMismatch = () =>
        expect(consoleErrors.filter((line) => /[Hh]ydration/.test(line))).toEqual([]);

    beforeAll(() => {
        vi.stubGlobal('Vue', vendored('vue.global.prod.js', 'Vue'));
        vi.stubGlobal('VueRouter', vendored('vue-router.global.prod.js', 'VueRouter'));
    });

    beforeEach(() => {
        // Vue reports a recovered mismatch as `Hydration completed but contains
        // mismatches.` — on the production build too, which is the only one this package
        // ships. It is the framework's own verdict on the markup, so it is worth asserting
        // directly rather than only inferring from node identity.
        consoleErrors = [];
        vi.spyOn(console, 'error').mockImplementation((...args) => {
            consoleErrors.push(args.map(String).join(' '));
        });

        // Nothing in these tests may reach the network: the whole point of an inlined
        // payload is that the first paint costs no request, and the broken version fetched
        // the page it was already displaying.
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('no network in this test')));
        // The router's `scrollBehavior` calls it on every resolved navigation and jsdom
        // has no implementation, which would otherwise bury the output in stack traces.
        vi.stubGlobal('scrollTo', vi.fn());
        window.history.replaceState({}, '', '/');
    });

    afterEach(() => {
        delete window.pwax;
    });

    it('adopts the server-rendered nodes instead of replacing them', async () => {
        const component = {
            template: '<div class="home"><h1>{{ title }}</h1><p>{{ count }}</p></div>',
            script: 'export default { data() { return { title: "Home", count: 3 }; } };',
            style: '',
        };

        const result = prerender({ url: '/', component, data: {} });

        expect(result.ok).toBe(true);

        await boot({ html: result.html, state: result.serializedState, component });

        const heading = document.querySelector('#pwax h1');

        expect(heading).not.toBeNull();
        expect(heading.textContent).toBe('Home');

        // The identity check. Had Vue mismatched, this node would have been discarded and
        // a new one created in its place.
        expect(document.querySelector('#pwax h1')).toBe(heading);
        expect(document.querySelector('#pwax p').textContent).toBe('3');
        assertNoMismatch();
    });

    it('renders the page once when the shell indents the prerendered markup', async () => {
        const component = {
            template: '<div class="home"><h1>{{ title }}</h1></div>',
            script: 'export default { data() { return { title: "Home" }; } };',
            style: '',
        };

        const result = prerender({ url: '/', component, data: {} });

        await boot({
            html: result.html,
            state: result.serializedState,
            component,
            indent: true,
        });

        // The symptom of a mismatch on the container's first child is not a subtle one:
        // Vue leaves the server's markup where it is and inserts a second, client-rendered
        // copy before it. Counting is the assertion.
        expect(document.querySelectorAll('#pwax main')).toHaveLength(1);
        expect(document.querySelectorAll('#pwax h1')).toHaveLength(1);
        expect(document.querySelector('#pwax h1').textContent).toBe('Home');
        assertNoMismatch();
    });

    it('does not fetch the page it is already displaying', async () => {
        const component = {
            template: '<div class="home"><h1>Home</h1></div>',
            script: '',
            style: '',
        };

        const result = prerender({ url: '/', component, data: {} });

        await boot({ html: result.html, state: result.serializedState, component });

        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('hydrates a page whose links are router-links', async () => {
        const component = {
            template:
                '<nav class="nav"><router-link to="/">Home</router-link>' +
                '<router-link to="/about">About</router-link></nav>',
            script: '',
            style: '',
        };

        const result = prerender({ url: '/', component, data: {} });

        await boot({ html: result.html, state: result.serializedState, component });

        const links = [...document.querySelectorAll('#pwax a')];

        expect(links).toHaveLength(2);

        const before = links.slice();

        expect([...document.querySelectorAll('#pwax a')]).toEqual(before);
        expect(links.map((a) => a.getAttribute('href'))).toEqual(['/', '/about']);

        // `RouterLink` renders `aria-current` and always binds `class`. The bridge stands
        // in for it, and a stand-in that is merely "roughly an <a>" mismatches on any page
        // with a nav.
        expect(links[0].getAttribute('aria-current')).toBe('page');
        expect(links[0].getAttribute('class')).toBe('router-link-active router-link-exact-active');
        assertNoMismatch();
    });

    it('seeds the component with the server state when data() disagrees', async () => {
        // A component whose `data()` is not a pure function of the document: on the server
        // it resolved to one value, and re-running it in the browser produces another. This
        // is the case the state island exists for, and the one the old merge order — the
        // author's `data()` winning — could not fix.
        const component = {
            template: '<div class="stamp">{{ renderedAt }}</div>',
            script: 'export default { data() { return { renderedAt: String(Date.now()) }; } };',
            style: '',
        };

        const result = prerender({ url: '/', component, data: {} });
        const server = result.serializedState.renderedAt;

        expect(typeof server).toBe('string');

        await boot({ html: result.html, state: result.serializedState, component });

        const stamp = document.querySelector('#pwax .stamp');

        expect(stamp.textContent).toBe(server);
        expect(window.pwax.ssrState).toEqual(result.serializedState);
        assertNoMismatch();
    });

    it('falls back to a client render when the page was not prerendered', async () => {
        // No `data-pwax-prerendered`, no `hydrate` flag: the ordinary SPA shell. The
        // runtime must mount rather than hydrate, and still cost no request.
        document.head.innerHTML = '';
        document.body.innerHTML = '<div id="pwax" class="pwax-preloader" tabindex="-1"></div>';

        island(
            'pwax-config',
            JSON.stringify({ mount: 'pwax', templates: TEMPLATES, pinia: false })
        );
        island(
            'pwax-initial',
            JSON.stringify({
                url: '/',
                component: { template: '<div class="home"><h1>Client</h1></div>', hash: 'h' },
            })
        );

        await bootAndWait('pwax:navigated');

        expect(document.querySelector('#pwax h1').textContent).toBe('Client');
        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(window.pwax.ssrState).toBeNull();
    });
});
