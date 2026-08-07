import { afterEach, describe, expect, it, vi } from 'vitest';
import { COMPONENT_HEADER, HttpError, LOCATION_HEADER, createHttp } from '../../src/js/http.js';

function response(body, { status = 200, headers = {}, redirected = false, url = '' } = {}) {
    return {
        ok: status >= 200 && status < 300,
        status,
        statusText: status === 404 ? 'Not Found' : 'OK',
        redirected,
        url,
        headers: new Headers({ 'Content-Type': 'application/json', ...headers }),
        json: async () => body,
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('http', () => {
    it('sends the component header on every request', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(response({ ok: true }));
        vi.stubGlobal('fetch', fetchSpy);

        await createHttp({ csrf: null }).json('/about');

        const [, init] = fetchSpy.mock.calls[0];
        expect(init.headers[COMPONENT_HEADER]).toBe('true');
        expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest');
        expect(init.credentials).toBe('same-origin');
    });

    it('includes the csrf token when configured', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(response({}));
        vi.stubGlobal('fetch', fetchSpy);

        await createHttp({ csrf: 'tok' }).json('/about');

        expect(fetchSpy.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('tok');
    });

    it('omits the csrf header when there is no token', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(response({}));
        vi.stubGlobal('fetch', fetchSpy);

        await createHttp({ csrf: null }).json('/about');

        expect(fetchSpy.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBeUndefined();
    });

    // A shared, mutable Headers object was a real defect in 1.x: anything that added a
    // header for one request added it to every later one.
    it('does not leak per-request headers into later requests', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(response({}));
        vi.stubGlobal('fetch', fetchSpy);

        const http = createHttp({ csrf: null });
        await http.json('/a', { headers: { 'X-One-Off': '1' } });
        await http.json('/b');

        expect(fetchSpy.mock.calls[0][1].headers['X-One-Off']).toBe('1');
        expect(fetchSpy.mock.calls[1][1].headers['X-One-Off']).toBeUndefined();
    });

    it('surfaces a full-reload instruction from the location header', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    response(
                        {},
                        { status: 409, headers: { [LOCATION_HEADER]: 'https://other.test/login' } }
                    )
                )
        );

        expect(await createHttp({}).json('/admin')).toEqual({
            __location: 'https://other.test/login',
        });
    });

    /**
     * `auth` and `VerifyCsrfToken` throw rather than returning a response, so their
     * redirects are produced by the exception handler outside the middleware pipeline
     * and cannot be translated server-side. `fetch` follows them silently, so a login
     * page arrives as a 200 full of HTML.
     */
    it('treats a followed redirect that returns html as a full reload', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                response(null, {
                    headers: { 'Content-Type': 'text/html' },
                    redirected: true,
                    url: 'http://app.test/login',
                })
            )
        );

        expect(await createHttp({}).json('/secret')).toEqual({
            __location: 'http://app.test/login',
        });
    });

    it('does not treat a redirect that still returns json as a reload', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue(
                    response({ template: '<p>ok</p>' }, { redirected: true, url: '/final' })
                )
        );

        expect(await createHttp({}).json('/start')).toEqual({ template: '<p>ok</p>' });
    });

    it('reloads when a 200 arrives that is not a component payload', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                response(null, {
                    headers: { 'Content-Type': 'text/html' },
                    url: '/maintenance',
                })
            )
        );

        expect(await createHttp({}).json('/page')).toEqual({ __location: '/maintenance' });
    });

    it('throws HttpError with the status on a failure', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(response({ error: 'nope' }, { status: 404 }))
        );

        await expect(createHttp({}).json('/missing')).rejects.toMatchObject({
            name: 'HttpError',
            status: 404,
            body: { error: 'nope' },
        });
    });

    it('passes an abort signal through', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(response({}));
        vi.stubGlobal('fetch', fetchSpy);

        const controller = new AbortController();
        await createHttp({}).json('/a', { signal: controller.signal });

        expect(fetchSpy.mock.calls[0][1].signal).toBe(controller.signal);
    });

    it('HttpError carries a readable message', () => {
        const error = new HttpError({ status: 500, statusText: 'Server Error' }, null);

        expect(error.message).toContain('500');
        expect(error).toBeInstanceOf(Error);
    });
});
