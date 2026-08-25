import { describe, expect, it } from 'vitest';
import { createApp } from 'vue';
import { DEFAULT_ERROR, DEFAULT_LOADER, pageTemplate } from '../../src/js/pageTemplate.mjs';

describe('pageTemplate', () => {
    it('falls back to the bundled loader and error markup', () => {
        const template = pageTemplate();

        expect(template).toContain(DEFAULT_LOADER);
        expect(template).toContain(DEFAULT_ERROR.trim());
    });

    it('uses the server-supplied fragments when it has them', () => {
        const template = pageTemplate({ loader: '<p>Wait</p>', error: '<p>Broken</p>' });

        expect(template).toContain('<p>Wait</p>');
        expect(template).toContain('<p>Broken</p>');
        expect(template).not.toContain(DEFAULT_LOADER);
        expect(template).not.toContain(DEFAULT_ERROR.trim());
    });

    it('wraps the page in a KeepAlive capped at the number of pages kept', () => {
        expect(pageTemplate({}, 12)).toContain('<KeepAlive :max="12">');

        // Nothing to retain, nothing to wrap it in.
        expect(pageTemplate({}, 0)).not.toContain('KeepAlive');
    });

    /*
     * The loader, the error screen and the page share one slot, and exactly one of them
     * may render. Flatten the guards and a page that errors mid-navigation renders the
     * error screen *and* the stale page underneath it.
     *
     * Rendered rather than string-matched. The branches are no longer a `v-if`/`v-else`
     * pair — `<KeepAlive>` has to stay mounted, so it sits outside the error branch — and
     * a test that asserts on the shape of the guards would pass or fail on how they are
     * spelled rather than on whether two things can be on screen at once.
     */
    describe.each([
        ['without KeepAlive', 0],
        ['with KeepAlive', 12],
    ])('exactly one branch renders (%s)', (_name, retain) => {
        const render = (state) => {
            const host = document.createElement('div');
            document.body.appendChild(host);

            createApp({
                template: pageTemplate({ loader: '<p id="loader"></p>' }, retain),
                data: () => ({
                    component: null,
                    error: null,
                    renderedKey: '/x#1',
                    keepState: true,
                    ...state,
                }),
                methods: { retry() {} },
            }).mount(host);

            return host;
        };

        const page = { template: '<p id="page"></p>' };

        it('shows the loader alone when there is no page yet', () => {
            const host = render({});

            expect(host.querySelector('#loader')).not.toBeNull();
            expect(host.querySelector('#page')).toBeNull();
            expect(host.querySelector('.pwax-error')).toBeNull();
        });

        it('shows the page alone once there is one', () => {
            const host = render({ component: page });

            expect(host.querySelector('#page')).not.toBeNull();
            expect(host.querySelector('#loader')).toBeNull();
            expect(host.querySelector('.pwax-error')).toBeNull();
        });

        it('shows the error alone, never over the top of a stale page', () => {
            const host = render({
                component: page,
                error: { status: 500, statusText: 'Oh no', message: 'Broken' },
            });

            expect(host.querySelector('.pwax-error')).not.toBeNull();
            expect(host.querySelector('#page')).toBeNull();
            expect(host.querySelector('#loader')).toBeNull();
        });
    });

    it('renders an opted-out page outside the KeepAlive, not inside it', () => {
        const host = document.createElement('div');
        document.body.appendChild(host);

        createApp({
            template: pageTemplate({}, 12),
            data: () => ({
                component: { template: '<p id="page"></p>' },
                error: null,
                renderedKey: '/x#1',
                keepState: false,
            }),
            methods: { retry() {} },
        }).mount(host);

        // Still rendered — opting out of retention is not opting out of being shown.
        expect(host.querySelectorAll('#page')).toHaveLength(1);
    });
});
