<script>
    const routes = [
        {
            path: '/:page(.*)',
            component: @import('~pwax/vue/loader'),
            beforeEnter: function(to, from, next) {
                next();
            }
        }
    ];

    const router = VueRouter.createRouter({
        @if(config('pwax.hash_route', true))
            history: VueRouter.createWebHashHistory(),
        @else
            history: VueRouter.createWebHistory(),
        @endif
        routes: routes,
    });

    router.beforeEach(function(to, from, next){
        next();
    });

    export default router;
</script>
