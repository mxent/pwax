/**
 * Runs the shipped service worker against a fake Cache API.
 *
 * The worker is a Blade view, so it is not importable and its behaviour has historically
 * been asserted by looking for strings in the rendered source. That catches nothing that
 * matters: whether an install survives a 404, whether an authenticated page ends up on
 * disk, and whether a deploy re-downloads the whole application are all questions about
 * what the code *does*.
 *
 * The worker is built from `src/js/sw/index.js` into memory before the suite runs, and
 * evaluated here with the same preamble the server writes. Until 4.1 it was a Blade
 * template, and this file emulated Blade badly enough to be its own hazard.
 */
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';

const HERE = dirname(fileURLToPath(import.meta.url));

export const ORIGIN = 'https://app.test';

export const WORKER_ENTRY = resolve(HERE, '../../../src/js/sw/index.js');

/** Set by `globalSetup`, which builds the worker once for the whole run. */
let bundled = null;

export function setWorkerBundle(source) {
    bundled = source;
}

function workerBundle() {
    if (bundled === null) {
        throw new Error(
            'pwax test: the service worker bundle was not built. `globalSetup` in ' +
                'vitest.config.js is what builds it.'
        );
    }

    return bundled;
}

/**
 * The headers the client runtime sends for a page payload — and therefore the ones the
 * worker must both send and key its cache on. Kept in step with `Pwax::VARY` on the
 * server and `createHttp()` in the runtime; a PHP test asserts the server half agrees.
 */
export const PAGE_HEADERS = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-Pwax-Component': 'true',
};

const urlOf = (r) => (typeof r === 'string' ? new URL(r, ORIGIN).href : new URL(r.url).href);

/** A service worker resolves relative URLs against its own scope; undici will not. */
class SWRequest extends Request {
    constructor(input, init) {
        super(typeof input === 'string' ? new URL(input, ORIGIN).href : input, init);
    }
}

export { SWRequest as Request };

/** `mode: 'navigate'` cannot be given to the Request constructor outside a browser. */
export function navigation(path) {
    return {
        url: new URL(path, ORIGIN).href,
        method: 'GET',
        mode: 'navigate',
        headers: new Headers(),
    };
}

/** Whatever a Cache method was handed, as the Request the Cache API would key on. */
const toRequest = (input) => (typeof input === 'string' ? new SWRequest(input) : input);

const headersOf = (request) => (request && request.headers) || new Headers();

/**
 * Does a stored entry answer this request, per the stored response's `Vary`?
 *
 * This is the whole reason the harness exists in the shape it does. A cache keyed on the
 * URL alone cannot tell a correct implementation from a broken one: page payloads carry
 * `Vary: X-Pwax-Component, X-Requested-With, Accept`, so an entry stored under a bare
 * `cache.put(urlString, …)` can never be matched by the runtime's request, which sends all
 * three. Modelling it here is what lets a test assert the *mechanism* rather than the
 * symptom.
 */
function answersRequest(entry, request, ignoreVary) {
    if (ignoreVary) {
        return true;
    }

    const vary = entry.response.headers.get('Vary');

    if (!vary) {
        return true;
    }

    // `Vary: *` means no stored response ever matches.
    if (vary.trim() === '*') {
        return false;
    }

    const stored = headersOf(entry.key);
    const incoming = headersOf(request);

    return vary
        .split(',')
        .map((field) => field.trim())
        .filter(Boolean)
        .every((field) => stored.get(field) === incoming.get(field));
}

class FakeCache {
    constructor(name) {
        this.name = name;
        /** @type {Map<string, Array<{key: Request, response: Response}>>} */
        this.map = new Map();
    }

    async put(request, response) {
        if (response.bodyUsed) {
            throw new TypeError('pwax test: response body already used');
        }

        const key = toRequest(request);
        const url = urlOf(key);
        const entry = { key, response: response.clone() };

        // Replacement follows the same Vary rule as lookup: a put overwrites only the
        // representation it would itself have matched, so two representations of one URL
        // can coexist exactly as they do in a browser.
        const kept = (this.map.get(url) || []).filter((held) => !answersRequest(held, key, false));

        kept.push(entry);
        this.map.set(url, kept);
    }

    async match(request, options = {}) {
        const entries = this.map.get(urlOf(request)) || [];
        const hit = entries.find((entry) =>
            answersRequest(entry, toRequest(request), options.ignoreVary)
        );

        return hit ? hit.response.clone() : undefined;
    }

    async keys() {
        return [...this.map.values()].flatMap((entries) => entries.map((entry) => entry.key));
    }

    async delete(request, options = {}) {
        const url = urlOf(request);
        const entries = this.map.get(url) || [];
        const kept = entries.filter(
            (entry) => !answersRequest(entry, toRequest(request), options.ignoreVary)
        );

        if (kept.length === entries.length) {
            return false;
        }

        if (kept.length === 0) {
            this.map.delete(url);
        } else {
            this.map.set(url, kept);
        }

        return true;
    }
}

export class FakeCaches {
    constructor() {
        this.store = new Map();
    }

    async open(name) {
        if (!this.store.has(name)) {
            this.store.set(name, new FakeCache(name));
        }

        return this.store.get(name);
    }

    async has(name) {
        return this.store.has(name);
    }

    async keys() {
        return [...this.store.keys()];
    }

    async delete(name) {
        return this.store.delete(name);
    }

    async match(request, options = {}) {
        for (const cache of this.store.values()) {
            const hit = await cache.match(request, options);

            if (hit) {
                return hit;
            }
        }

        return undefined;
    }
}

/**
 * The worker as the server serves it: a preamble, then the built bundle.
 *
 * This used to crudely emulate Blade — strip comments, substitute `@json()` calls
 * positionally — because the worker lived inside a `.blade.php` file and could not be
 * imported. It is ordinary JavaScript now, so the harness runs the real thing.
 *
 * The bundle is built into memory by `globalSetup`, not read from `dist/`, so a test can
 * never pass against a stale build. CI's `git diff --exit-code dist/` still catches an
 * uncommitted one separately.
 */
export function render(manifest) {
    const preamble = {
        manifestUrl: '/sw.json',
        manifestHash: manifest.hash,
        prefix: manifest.cachePrefix || 'pwax',
        config: {
            hash: manifest.hash,
            strategy: manifest.strategy,
            maxEntries: manifest.maxEntries,
            maxEntryBytes: manifest.maxEntryBytes,
            navigationPreload: manifest.navigationPreload,
            navigationStrategy: manifest.navigationStrategy,
            navigationUrls: manifest.navigationUrls,
            shellUrl: manifest.shellUrl,
            offlineUrl: manifest.offlineUrl,
            assetPrefixes: manifest.assetPrefixes,
            pageHeaders: manifest.pageHeaders || PAGE_HEADERS,
            crossOrigin: manifest.crossOrigin,
            concurrency: manifest.concurrency,
            push: manifest.push || {},
            // Stands in for `pwax::js.offline`, which the server renders. Kept to the
            // same copy so the tests about what a visitor is shown still mean something;
            // a PHP test asserts the view itself says it.
            offlineHtml:
                manifest.offlineHtml ||
                '<!DOCTYPE html><html lang="en" dir="auto"><head><meta charset="utf-8">' +
                    '<title>Offline</title><style>body{margin:0}</style></head><body>' +
                    '<div role="alert"><h1>This page is not available offline</h1>' +
                    '<p>It has not been stored on this device. Reconnect and try again.</p>' +
                    '</div></body></html>',
        },
    };

    return `self.__PWAX_SW__ = ${JSON.stringify(preamble)};\n${workerBundle()}`;
}

/**
 * Evaluate a rendered worker in a sandbox with a fake Cache API and a stub network.
 *
 * @param {{manifest: object, caches?: FakeCaches, routes: (path: string) => Response|null}} options
 */
export function createWorker(options) {
    const { manifest, caches = new FakeCaches(), routes } = options;
    const listeners = {};
    const fetches = [];
    const requests = [];
    const log = [];

    /** Notifications the worker asked to show, and windows it touched. */
    const notifications = [];
    const windows = [];
    const syncTags = [];

    const self = {
        location: new URL('/service-worker.js', ORIGIN),
        addEventListener: (name, fn) => (listeners[name] ||= []).push(fn),
        skipWaiting: () => log.push('skipWaiting'),
        clients: {
            claim: async () => log.push('claim'),
            matchAll: async () => options.clients || [],
            openWindow: async (url) => {
                windows.push({ opened: url });

                return { url };
            },
        },
        registration: {
            navigationPreload: { enable: async () => log.push('preload') },
            showNotification: async (title, opts) => notifications.push({ title, ...opts }),
            sync: {
                register: async (tag) => {
                    if (options.backgroundSync === false) {
                        throw new Error('unsupported');
                    }

                    syncTags.push(tag);
                },
            },
        },
    };

    const sandbox = {
        self,
        caches,
        fetch: async (input) => {
            const request = toRequest(input);
            const url = urlOf(request);

            fetches.push(url);
            // The whole Request too, so a test can assert what the worker actually asked
            // for — which headers it sent and whether it sent cookies. Those are the
            // difference between fetching a page's JSON payload and its HTML shell, and
            // between precaching the guest rendering and one user's private one.
            requests.push(request);

            const parsed = new URL(url);

            // Awaited, so a route handler may be async — which is how a test observes
            // how many requests the worker has in flight at once.
            const response = await routes(parsed.pathname + parsed.search, request);

            if (!response) {
                throw new TypeError('Failed to fetch');
            }

            return response;
        },
        Request: SWRequest,
        Response,
        Headers,
        URL,
        // `withTimeout` needs these, and without them every strategy that sets a deadline
        // — `pages.timeout`, a data group's `timeout` — threw `setTimeout is not defined`
        // the moment a test configured one, which read as the worker failing to answer.
        setTimeout,
        clearTimeout,
        // Every level is recorded: the worker distinguishes a failure (warn) from a
        // deliberate skip (info), and a harness that dropped one of them could not tell
        // an alarming install from a correct one.
        console: {
            warn: (...args) => log.push(['warn', ...args]),
            info: (...args) => log.push(['info', ...args]),
            error: (...args) => log.push(['error', ...args]),
        },
    };

    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(render(manifest), sandbox, { filename: 'service-worker.js' });

    /**
     * Dispatch an event exactly as given, awaiting nothing.
     *
     * `dispatch` below settles every `waitUntil` before it returns, which is what almost
     * every test wants and precisely what a test about *ordering* must not do. Whether the
     * response comes back without waiting for the cache write is only observable if the
     * caller supplies its own `waitUntil` and keeps hold of the promises.
     */
    const emit = (name, event) => {
        for (const fn of listeners[name] || []) {
            fn(event);
        }
    };

    /** Dispatch an event and settle everything it passed to `waitUntil`. */
    const dispatch = async (name, event = {}) => {
        const waits = [];
        const responses = [];

        const wrapped = {
            ...event,
            waitUntil: (promise) => waits.push(promise),
            respondWith: (promise) => responses.push(promise),
        };

        for (const fn of listeners[name] || []) {
            fn(wrapped);
        }

        await Promise.all(
            waits.map((promise) =>
                promise.catch((error) => log.push(['rejected', name, error.message]))
            )
        );

        return responses.length ? responses[0] : undefined;
    };

    const request = (path, init = {}) =>
        dispatch('fetch', {
            request: new SWRequest(path, init),
            preloadResponse: Promise.resolve(null),
        });

    const navigate = (path) =>
        dispatch('fetch', { request: navigation(path), preloadResponse: Promise.resolve(null) });

    /** Did a `waitUntil` promise reject — i.e. did install or activate fail? */
    const failed = () => log.some((entry) => Array.isArray(entry) && entry[0] === 'rejected');

    /** The Request the worker sent for a URL, for asserting headers and credentials. */
    const sentRequest = (path) =>
        requests.find((sent) => new URL(sent.url).pathname + new URL(sent.url).search === path);

    return {
        dispatch,
        emit,
        request,
        navigate,
        caches,
        fetches,
        requests,
        sentRequest,
        log,
        failed,
        notifications,
        windows,
        syncTags,
    };
}
