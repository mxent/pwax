/**
 * Runs the shipped service worker against a fake Cache API.
 *
 * The worker is a Blade view, so it is not importable and its behaviour has historically
 * been asserted by looking for strings in the rendered source. That catches nothing that
 * matters: whether an install survives a 404, whether an authenticated page ends up on
 * disk, and whether a deploy re-downloads the whole application are all questions about
 * what the code *does*.
 *
 * The Blade rendering here is deliberately crude — it substitutes `@json()` calls and
 * strips comments — because the worker only uses those two constructs. If that stops
 * being true, these tests fail loudly rather than silently testing nothing.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';

const HERE = dirname(fileURLToPath(import.meta.url));

export const ORIGIN = 'https://app.test';

export const WORKER_VIEW = resolve(HERE, '../../../resources/views/js/service-worker.blade.php');

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

class FakeCache {
    constructor(name) {
        this.name = name;
        this.map = new Map();
    }

    async put(request, response) {
        if (response.bodyUsed) {
            throw new TypeError('pwax test: response body already used');
        }

        this.map.set(urlOf(request), response.clone());
    }

    async match(request) {
        const hit = this.map.get(urlOf(request));

        return hit ? hit.clone() : undefined;
    }

    async keys() {
        return [...this.map.keys()].map((url) => new SWRequest(url));
    }

    async delete(request) {
        return this.map.delete(urlOf(request));
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

    async match(request) {
        for (const cache of this.store.values()) {
            const hit = await cache.match(request);

            if (hit) {
                return hit;
            }
        }

        return undefined;
    }
}

/**
 * Render the Blade worker the way `PwaxController::serviceWorker()` does.
 */
export function render(manifest) {
    const blade = readFileSync(WORKER_VIEW, 'utf8');

    const source = blade
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@php[\s\S]*?@endphp/g, '')
        .replace(/\{\{[\s\S]*?\}\}/g, 'x');

    // The four `@json()` calls, in source order: the manifest URL, its hash, the cache
    // prefix, and the inlined routing config.
    const values = [
        '/sw.json',
        manifest.hash,
        manifest.cachePrefix || 'pwax',
        {
            hash: manifest.hash,
            version: manifest.version,
            strategy: manifest.strategy,
            maxEntries: manifest.maxEntries,
            navigationPreload: manifest.navigationPreload,
            shellUrl: manifest.shellUrl,
            offlineUrl: manifest.offlineUrl,
            assetPrefixes: manifest.assetPrefixes,
        },
    ];

    let out = '';
    let cursor = 0;
    let index = 0;

    for (;;) {
        const start = source.indexOf('@json(', cursor);

        if (start === -1) {
            break;
        }

        out += source.slice(cursor, start);

        let depth = 0;
        let end = start + 5;

        for (; end < source.length; end++) {
            if (source[end] === '(') {
                depth++;
            } else if (source[end] === ')' && --depth === 0) {
                break;
            }
        }

        out += JSON.stringify(values[index++]);
        cursor = end + 1;
    }

    if (index !== values.length) {
        throw new Error(
            `pwax test: expected ${values.length} @json() calls in the worker, found ${index}. ` +
                'The harness substitutes them positionally and must be updated alongside the view.'
        );
    }

    return out + source.slice(cursor);
}

/**
 * Evaluate a rendered worker in a sandbox with a fake Cache API and a stub network.
 *
 * @param {{manifest: object, caches?: FakeCaches, routes: (path: string) => Response|null}} options
 */
export function createWorker({ manifest, caches = new FakeCaches(), routes }) {
    const listeners = {};
    const fetches = [];
    const log = [];

    const self = {
        location: new URL('/service-worker.js', ORIGIN),
        addEventListener: (name, fn) => (listeners[name] ||= []).push(fn),
        skipWaiting: () => log.push('skipWaiting'),
        clients: { claim: async () => log.push('claim') },
        registration: { navigationPreload: { enable: async () => log.push('preload') } },
    };

    const sandbox = {
        self,
        caches,
        fetch: async (input) => {
            const url = urlOf(input);
            fetches.push(url);

            const parsed = new URL(url);
            const response = routes(parsed.pathname + parsed.search);

            if (!response) {
                throw new TypeError('Failed to fetch');
            }

            return response;
        },
        Request: SWRequest,
        Response,
        Headers,
        URL,
        console: {
            warn: (...args) => log.push(['warn', ...args]),
            info: () => {},
            error: () => {},
        },
    };

    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(render(manifest), sandbox, { filename: 'service-worker.js' });

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

    return { dispatch, request, navigate, caches, fetches, log, failed };
}
