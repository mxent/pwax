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
        const hit = entries.find((entry) => answersRequest(entry, toRequest(request), options.ignoreVary));

        return hit ? hit.response.clone() : undefined;
    }

    async keys() {
        return [...this.map.values()].flatMap((entries) => entries.map((entry) => entry.key));
    }

    async delete(request, options = {}) {
        const url = urlOf(request);
        const entries = this.map.get(url) || [];
        const kept = entries.filter((entry) => !answersRequest(entry, toRequest(request), options.ignoreVary));

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
 * Reject a `@json()` argument that Blade cannot actually compile.
 *
 * Blade's `@json` is naive: it does `explode(',', $expression)` and reads the parts as
 * (value, flags, depth). An array literal written inside the directive is therefore torn
 * apart at its commas and emitted as a PHP syntax error — which the view only reveals
 * when it is rendered, and which this harness would otherwise paper over by substituting
 * the expression wholesale.
 */
function assertBladeCanCompile(expression) {
    const parts = expression.split(',');

    if (parts.length > 3) {
        throw new Error(
            `pwax test: @json(${expression.trim().slice(0, 40)}…) has ${parts.length} ` +
                'comma-separated parts. Blade reads them as (value, flags, depth), so this ' +
                'compiles to invalid PHP. Build the value in the @php block and pass one variable.'
        );
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
            navigationStrategy: manifest.navigationStrategy,
            navigationUrls: manifest.navigationUrls,
            shellUrl: manifest.shellUrl,
            offlineUrl: manifest.offlineUrl,
            assetPrefixes: manifest.assetPrefixes,
            pageHeaders: manifest.pageHeaders || PAGE_HEADERS,
            crossOrigin: manifest.crossOrigin,
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

        assertBladeCanCompile(source.slice(start + 6, end));

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
    const requests = [];
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

    return { dispatch, request, navigate, caches, fetches, requests, sentRequest, log, failed };
}
