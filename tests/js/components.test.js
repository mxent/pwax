import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createComponentLoader } from '../../src/js/components.js';
import { resetModuleCache, setImporter } from '../../src/js/modules.js';
import { createStyleManager } from '../../src/js/styles.js';

/** Minimal stand-in for the global Vue build. */
function stubVue() {
    const Vue = {
        defineAsyncComponent: vi.fn((loader) => ({ __asyncLoader: loader })),
        // The default build has a template compiler, and the runtime checks for exactly
        // this before it will hand Vue a component that still carries a template string.
        compile: vi.fn(() => () => null),
    };

    vi.stubGlobal('Vue', Vue);

    return Vue;
}

function loader() {
    return createComponentLoader({ styles: createStyleManager(document) });
}

describe('component loader', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        resetModuleCache();
        vi.unstubAllGlobals();
    });

    it('explains itself when Vue is missing', () => {
        vi.stubGlobal('Vue', undefined);

        expect(() => loader().component('/c/a.js')).toThrow(/Vue is not loaded/);
    });

    /**
     * Vue treats every `defineAsyncComponent` result as a distinct component type, so a
     * fresh one per call would remount the subtree on each parent render and defeat
     * `<KeepAlive>`.
     */
    it('returns the same component for the same url', () => {
        const Vue = stubVue();
        const load = loader();

        expect(load.component('/c/a.js')).toBe(load.component('/c/a.js'));
        expect(Vue.defineAsyncComponent).toHaveBeenCalledTimes(1);
    });

    it('keeps distinct urls and exports apart', () => {
        stubVue();
        const load = loader();

        const a = load.component('/c/a.js');
        const b = load.component('/c/b.js');
        const named = load.component('/c/a.js', 'Backdrop');

        expect(a).not.toBe(b);
        expect(a).not.toBe(named);
    });

    /**
     * `components: { Button: () => @pwaxImport('button') }` is the Vue 2 idiom, dropped in
     * Vue 3. Vue calls the arrow as a functional component, gets this object instead of
     * vnodes, and renders `String(child)` — which is `[object Object]` by default, with
     * no warning. The message has to land where the developer is looking: on the page.
     */
    it('renders an explanation rather than [object Object] when used as text', () => {
        stubVue();

        const text = String(loader().component('/c/button.js'));

        expect(text).not.toBe('[object Object]');
        expect(text).toContain('arrow function');
        expect(text).toContain("@pwaxImport('…')");
    });

    it('loads a component, applying its styles', async () => {
        stubVue();
        setImporter(
            vi.fn().mockResolvedValue({
                default: { name: 'Button' },
                __pwaxTemplate: '<button></button>',
                __pwaxStyle: '.btn{}',
            })
        );

        const styles = createStyleManager(document);
        const options = await createComponentLoader({ styles }).load('/c/button.js');

        expect(options.name).toBe('Button');
        expect(options.template).toBe('<button></button>');
        expect(document.querySelector('style[data-pwax-style="/c/button.js"]')).not.toBeNull();
    });

    it('selects a named export', async () => {
        stubVue();
        const Backdrop = { name: 'Backdrop' };
        setImporter(vi.fn().mockResolvedValue({ default: {}, Backdrop }));

        expect(await loader().load('/c/modal.js', 'Backdrop')).toBe(Backdrop);
    });
});
