/**
 * Tests for the test harness.
 *
 * Ordinarily a fake needs no tests of its own. This one does, because its fidelity on a
 * single point decides whether an entire class of bug is visible at all.
 *
 * The Cache API matches a stored entry against a request using the stored response's
 * `Vary` header. Pwax page payloads are served with `Vary: X-Pwax-Component,
 * X-Requested-With, Accept`, and the client runtime sends all three. A worker that stores
 * a page with `cache.put(urlString, response)` therefore stores it under a key with none
 * of them — and every subsequent lookup misses, forever, for a reason nothing in the code
 * makes visible.
 *
 * A fake cache keyed on the URL alone cannot tell that implementation from a correct one.
 * These tests pin the behaviour that makes the difference observable.
 */
import { describe, expect, it } from 'vitest';

import { FakeCaches, ORIGIN, Request } from './helpers/serviceWorkerHarness.js';

const VARY = 'X-Pwax-Component, X-Requested-With, Accept';

const PAGE_HEADERS = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-Pwax-Component': 'true',
};

const payload = (body = { component: 'pages.about' }) =>
    new Response(JSON.stringify(body), {
        headers: { 'Content-Type': 'application/json', Vary: VARY },
    });

describe('the fake Cache API models Vary', () => {
    it('misses when the stored key lacks the headers the response varies on', async () => {
        const cache = await new FakeCaches().open('pwax-pages');

        // Exactly what the worker does today: a bare URL as the key.
        await cache.put('/about', payload());

        const hit = await cache.match(new Request('/about', { headers: PAGE_HEADERS }));

        expect(hit).toBeUndefined();
    });

    it('hits when the stored key carries them', async () => {
        const cache = await new FakeCaches().open('pwax-pages');

        await cache.put(new Request('/about', { headers: PAGE_HEADERS }), payload());

        const hit = await cache.match(new Request('/about', { headers: PAGE_HEADERS }));

        expect(hit).toBeDefined();
        await expect(hit.json()).resolves.toEqual({ component: 'pages.about' });
    });

    it('hits regardless when the lookup opts out of Vary', async () => {
        const cache = await new FakeCaches().open('pwax-pages');

        await cache.put('/about', payload());

        const hit = await cache.match(new Request('/about', { headers: PAGE_HEADERS }), {
            ignoreVary: true,
        });

        expect(hit).toBeDefined();
    });

    it('keeps two representations of one URL apart', async () => {
        const cache = await new FakeCaches().open('pwax-pages');

        await cache.put(new Request('/about'), new Response('<html>', { headers: { Vary: VARY } }));
        await cache.put(new Request('/about', { headers: PAGE_HEADERS }), payload());

        const html = await cache.match(new Request('/about'));
        const json = await cache.match(new Request('/about', { headers: PAGE_HEADERS }));

        await expect(html.text()).resolves.toBe('<html>');
        await expect(json.text()).resolves.toContain('pages.about');
    });

    it('replaces only the representation a put would itself have matched', async () => {
        const cache = await new FakeCaches().open('pwax-pages');

        await cache.put(new Request('/about'), new Response('<html>', { headers: { Vary: VARY } }));
        await cache.put(new Request('/about', { headers: PAGE_HEADERS }), payload());
        await cache.put(new Request('/about', { headers: PAGE_HEADERS }), payload({ component: 'v2' }));

        expect(await cache.keys()).toHaveLength(2);

        const json = await cache.match(new Request('/about', { headers: PAGE_HEADERS }));

        await expect(json.text()).resolves.toContain('v2');
    });

    it('ignores Vary entirely when the response does not set it', async () => {
        const cache = await new FakeCaches().open('pwax-precache');

        await cache.put('/__pwax__/pwax.js', new Response('export {}'));

        const hit = await cache.match(new Request('/__pwax__/pwax.js', { headers: PAGE_HEADERS }));

        expect(hit).toBeDefined();
    });

    it('never matches a response that varies on everything', async () => {
        const cache = await new FakeCaches().open('pwax-runtime');

        await cache.put('/whoami', new Response('{}', { headers: { Vary: '*' } }));

        expect(await cache.match(new Request('/whoami'))).toBeUndefined();
    });

    it('keeps the stored key requests, headers and all', async () => {
        const cache = await new FakeCaches().open('pwax-pages');

        await cache.put(new Request('/about', { headers: PAGE_HEADERS }), payload());

        const [key] = await cache.keys();

        expect(key.url).toBe(`${ORIGIN}/about`);
        expect(key.headers.get('X-Pwax-Component')).toBe('true');
    });

    it('deletes only the matching representation', async () => {
        const cache = await new FakeCaches().open('pwax-pages');

        await cache.put(new Request('/about'), new Response('<html>', { headers: { Vary: VARY } }));
        await cache.put(new Request('/about', { headers: PAGE_HEADERS }), payload());

        expect(await cache.delete(new Request('/about', { headers: PAGE_HEADERS }))).toBe(true);
        expect(await cache.keys()).toHaveLength(1);
        expect(await cache.match(new Request('/about'))).toBeDefined();
    });
});
