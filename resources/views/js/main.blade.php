<script>

    document.addEventListener('DOMContentLoaded', async function() {

        const baseComp = @import('~pwax/vue/app');
        window.app = Vue.createApp(baseComp);

        window.router = @import('~pwax/vue/router');
        app.use(router);

        window.pinia = Pinia.createPinia();
        app.use(pinia);

        $('#app').removeClass('preloader');

        app.mount('#app');

    });

</script>
