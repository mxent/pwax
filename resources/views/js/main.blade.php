<script>
    document.addEventListener('DOMContentLoaded', async function() {

        const baseComp = @import('~pwax/components/vue/app');
        window.app = Vue.createApp(baseComp);

        window.router = @import('~pwax/components/vue/router');
        app.use(router);

        window.pinia = Pinia.createPinia();
        app.use(pinia);

        app.mount('#app');

        document.getElementById('app').classList.remove('preloader');

    });
</script>
