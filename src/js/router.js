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

            if (to.hash) {
                return { el: to.hash, behavior: 'smooth' };
            }

            return { top: 0 };
        },
    });

    return router;
}
