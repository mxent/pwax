/**
 * Vue Router setup.
 *
 * A single catch-all route hands every path to the page component, which asks the
 * server what to render. Routing therefore stays defined in `routes/web.php` — there is
 * no second route table in JavaScript to keep in sync.
 */

export function createRouter({ page, config }) {
    if (typeof VueRouter === 'undefined') {
        throw new Error(
            'pwax: Vue Router is not loaded. Check that its script tag comes before pwax.js.'
        );
    }

    const history = config.hashRouting
        ? VueRouter.createWebHashHistory()
        : VueRouter.createWebHistory(config.base || '/');

    const router = VueRouter.createRouter({
        history,
        routes: [
            {
                path: '/:pathMatch(.*)*',
                name: 'pwax.page',
                component: page,
            },
        ],
        scrollBehavior(to, from, saved) {
            if (saved) {
                return saved;
            }

            if (!to.hash) {
                return { top: 0 };
            }

            /*
             * Smooth, unless the visitor has asked for less motion.
             *
             * A smooth scroll is animated motion across the whole viewport, and it is a
             * known migraine and vestibular-disorder trigger — the same reason the spinner,
             * the progress bar and the page transition all check this. Unlike those it is
             * driven from JavaScript, so no stylesheet can turn it off: the query has to be
             * asked here.
             *
             * `matchMedia` is feature-detected because a non-browser DOM may not have it,
             * and the fallback is the accessible answer rather than the pretty one.
             */
            const reduced =
                typeof window.matchMedia !== 'function' ||
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            return { el: to.hash, behavior: reduced ? 'auto' : 'smooth' };
        },
    });

    return router;
}
