/**
 * Fetching a page just before it is asked for.
 *
 * With the service worker off — the default — the gap between clicking a link and seeing
 * the page is entirely one request. A visitor says where they are going before they go,
 * and spending that time on the request is free.
 *
 * Bounded on purpose: a page payload can carry a signed-in visitor's data, so this is a
 * head start held in memory, not a cache.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPrefetcher } from '../../src/js/prefetch.js';

function server() {
    const asked = [];

    return {
        asked,
        json: vi.fn(async (path) => {
            asked.push(path);

            return { template: `<p>${path}</p>` };
        }),
    };
}

describe('taking a head start', () => {
    let prefetcher;

    afterEach(() => {
        prefetcher?.stop();
        document.body.innerHTML = '';
    });

    it('fetches once for repeated calls', async () => {
        const http = server();
        prefetcher = createPrefetcher(http, { mode: false });

        prefetcher.prefetch('/a');
        prefetcher.prefetch('/a');

        expect(http.json).toHaveBeenCalledTimes(1);
    });

    it('hands the payload to the navigation that follows', async () => {
        const http = server();
        prefetcher = createPrefetcher(http, { mode: false });

        prefetcher.prefetch('/a');

        await expect(prefetcher.take('/a')).resolves.toEqual({ template: '<p>/a</p>' });
        expect(http.json).toHaveBeenCalledTimes(1);
    });

    it('gives a payload up exactly once', async () => {
        const http = server();
        prefetcher = createPrefetcher(http, { mode: false });

        prefetcher.prefetch('/a');
        await prefetcher.take('/a');

        // Good for the navigation it was fetched for and no other. Storing pages for
        // later is the service worker's job, with rules about it.
        expect(prefetcher.take('/a')).toBeNull();
    });

    it('returns nothing for a path never prefetched', () => {
        prefetcher = createPrefetcher(server(), { mode: false });

        expect(prefetcher.take('/never')).toBeNull();
    });

    it('bounds what it holds', async () => {
        const http = server();
        prefetcher = createPrefetcher(http, { mode: false });

        for (let i = 0; i < 12; i += 1) {
            prefetcher.prefetch(`/page-${i}`);
        }

        // Oldest out first. Each entry is a whole page payload, and eight is more than
        // anyone is about to click.
        expect(prefetcher.take('/page-0')).toBeNull();
        expect(prefetcher.take('/page-11')).not.toBeNull();
    });

    it('forgets a prefetch that failed', async () => {
        const http = { json: vi.fn(async () => Promise.reject(new Error('offline'))) };
        prefetcher = createPrefetcher(http, { mode: false });

        // Must not reject: nobody asked for this, and an unhandled rejection from a
        // speculative request is noise in every console.
        await expect(prefetcher.prefetch('/a')).resolves.toBeNull();
    });
});

describe('watching for intent', () => {
    let prefetcher;

    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = `
            <a id="internal" href="/settings">Settings</a>
            <a id="external" href="https://elsewhere.test/x">Away</a>
            <a id="blank" href="/other" target="_blank">New tab</a>
            <a id="download" href="/report.pdf" download>Report</a>
            <a id="opted-out" href="/heavy" data-pwax-prefetch="off">Heavy</a>
        `;
    });

    afterEach(() => {
        prefetcher?.stop();
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    const hover = (id) => {
        document.getElementById(id).dispatchEvent(new Event('pointerenter', { bubbles: false }));
        vi.advanceTimersByTime(200);
    };

    it('fetches a link the pointer settles on', () => {
        const http = server();
        prefetcher = createPrefetcher(http, {});

        hover('internal');

        expect(http.asked).toEqual(['/settings']);
    });

    it('waits for intent rather than fetching every link crossed', () => {
        const http = server();
        prefetcher = createPrefetcher(http, {});

        document.getElementById('internal').dispatchEvent(new Event('pointerenter'));
        // Left again before the delay elapsed — a pointer crossing a list, not aiming.
        document.getElementById('internal').dispatchEvent(new Event('pointerleave'));
        vi.advanceTimersByTime(200);

        expect(http.asked).toEqual([]);
    });

    it('fetches on keyboard focus too', () => {
        const http = server();
        prefetcher = createPrefetcher(http, {});

        document.getElementById('internal').dispatchEvent(new Event('focusin', { bubbles: true }));
        vi.advanceTimersByTime(200);

        expect(http.asked).toEqual(['/settings']);
    });

    it('leaves alone every link the router will not handle', () => {
        const http = server();
        prefetcher = createPrefetcher(http, {});

        for (const id of ['external', 'blank', 'download', 'opted-out']) {
            hover(id);
        }

        expect(http.asked).toEqual([]);
    });

    it('does nothing at all when switched off', () => {
        const http = server();
        prefetcher = createPrefetcher(http, { mode: false });

        hover('internal');

        expect(http.asked).toEqual([]);
    });
});
