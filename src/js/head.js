/**
 * Keeping the document head in step with a client-side navigation.
 *
 * A browser replaces the whole document on a real navigation, head included. A router
 * replaces the body and leaves the head exactly as the previous page left it — so without
 * this the description, the canonical URL and every Open Graph tag describe whichever page
 * the visitor happened to land on first, for the rest of the session. That is worse than
 * setting none of them: a wrong answer outlives a missing one.
 *
 * Only tags this package emitted are touched. They carry `data-pwax-head`, so anything the
 * application pushed into `@stack('pwax-head')` is left alone — it belongs to the document,
 * not to the page.
 */

const MANAGED = 'data-pwax-head';

/**
 * Apply a page's metadata, removing whatever the previous page left behind.
 *
 * @param {{title?: string, description?: string, canonical?: string,
 *          meta?: Array<{attribute: string, key: string, content: string}>}|null|undefined} head
 */
export function applyHead(head) {
    if (!head) {
        return;
    }

    if (head.title) {
        document.title = head.title;
    }

    description(head.description);
    canonical(head.canonical);
    managed(head.meta || []);
}

/**
 * `<meta name="description">` is not marked as managed, because the shell emits one for
 * the application as a whole and a page that sets none should keep it rather than lose it.
 *
 * @param {string|undefined} content
 */
function description(content) {
    if (!content) {
        return;
    }

    let tag = document.head.querySelector('meta[name="description"]');

    if (!tag) {
        tag = document.createElement('meta');
        tag.setAttribute('name', 'description');
        document.head.appendChild(tag);
    }

    tag.setAttribute('content', content);
}

/**
 * The canonical link, removed when a page declares none.
 *
 * Unlike the description there is no sensible application-wide fallback: one URL cannot be
 * canonical for every route, and leaving the previous page's is the specific mistake this
 * whole module exists to prevent.
 *
 * @param {string|undefined} href
 */
function canonical(href) {
    const existing = document.head.querySelector('link[rel="canonical"]');

    if (!href) {
        existing?.remove();

        return;
    }

    if (existing) {
        existing.setAttribute('href', href);

        return;
    }

    const link = document.createElement('link');

    link.setAttribute('rel', 'canonical');
    link.setAttribute('href', href);
    document.head.appendChild(link);
}

/**
 * Replace every managed tag in one pass.
 *
 * Removed and rebuilt rather than diffed: the set is small, the tags carry no state, and a
 * diff would have to reason about a page that dropped a tag the previous one set — which is
 * the case that goes wrong.
 *
 * @param {Array<{attribute: string, key: string, content: string}>} meta
 */
function managed(meta) {
    for (const tag of document.head.querySelectorAll(`meta[${MANAGED}]`)) {
        tag.remove();
    }

    for (const { attribute, key, content } of meta) {
        if (!attribute || !key || !content) {
            continue;
        }

        const tag = document.createElement('meta');

        tag.setAttribute(attribute, key);
        tag.setAttribute('content', content);
        tag.setAttribute(MANAGED, '');
        document.head.appendChild(tag);
    }
}
