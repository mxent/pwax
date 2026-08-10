/**
 * HTTP access for the runtime.
 *
 * Every request carries the header that tells the server to answer with a component
 * payload rather than the SPA shell, plus the CSRF token when one is available.
 *
 * The two header constants below are the client-side mirror of `Pwax::HEADER` and
 * `Pwax::LOCATION_HEADER` in `src/Pwax.php`. They must agree; the
 * `HeaderConstantsTest` feature test asserts that.
 */

export const COMPONENT_HEADER = 'X-Pwax-Component';
export const LOCATION_HEADER = 'X-Pwax-Location';

export class HttpError extends Error {
    constructor(response, body) {
        super(`pwax: HTTP ${response.status} ${response.statusText || ''}`.trim());
        this.name = 'HttpError';
        this.status = response.status;
        this.statusText = response.statusText;
        this.body = body;
    }
}

export function createHttp(config) {
    /**
     * Headers sent with every runtime request.
     *
     * Returned fresh each time: a `Headers` instance is mutable, and 1.x shared a single
     * one across every call, so anything that added a header to one request silently
     * added it to all of them.
     */
    function headers(extra = {}) {
        const base = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            [COMPONENT_HEADER]: 'true',
        };

        if (config.csrf) {
            base['X-CSRF-TOKEN'] = config.csrf;
        }

        return { ...base, ...extra };
    }

    /**
     * Fetch JSON from the application.
     *
     * @param {string} url
     * @param {RequestInit & {signal?: AbortSignal}} options
     */
    async function json(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: headers(options.headers || {}),
        });

        // An off-site redirect the server translated for us.
        const location = response.headers.get(LOCATION_HEADER);
        if (location) {
            return { __location: location };
        }

        // `fetch` follows redirects silently and cannot be told not to, so a 302 to a
        // login page arrives here as a 200 full of HTML. The server cannot translate
        // this one: Laravel's `auth` middleware *throws* rather than returning a
        // response, so the redirect is produced by the exception handler, outside the
        // middleware pipeline. `redirected` is the only reliable signal it happened.
        if (response.redirected && !isJson(response)) {
            return { __location: response.url || url };
        }

        const payload = await readJson(response);

        if (!response.ok) {
            throw new HttpError(response, payload);
        }

        // A 200 that is not JSON means something served a page where a component was
        // expected — a maintenance screen, a proxy interstitial, a captive portal.
        // Reloading surfaces it to the user rather than failing silently.
        if (payload === null && !isJson(response)) {
            return { __location: response.url || url };
        }

        return payload;
    }

    function isJson(response) {
        return (response.headers.get('Content-Type') || '').includes('json');
    }

    async function readJson(response) {
        if (!isJson(response)) {
            return null;
        }

        try {
            return await response.json();
        } catch {
            return null;
        }
    }

    return { headers, json };
}
