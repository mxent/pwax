{{--
    Everything settle mode exists to capture: state set after mount, a style injected into
    <head> by application code, and an element appended to <body> outside the mount.
--}}
<template>
    <div class="settled">
        <h1 id="state">@{{ state }}</h1>
        <div id="rich" v-html="markup"></div>
    </div>
</template>

<script>
    export default {
        data() {
            return { state: 'before mount', markup: '<em id="inner">from v-html</em>' };
        },
        mounted() {
            // Deliberately past a poll round, so a renderer that only watches whether the
            // DOM changed in the last few milliseconds declares this page stable too early.
            setTimeout(() => {
                this.state = 'after mount';

                const style = document.createElement('style');
                style.textContent = '#state { color: rebeccapurple }';
                document.head.appendChild(style);

                const banner = document.createElement('div');
                banner.id = 'cookie-banner';
                banner.textContent = 'outside the mount';
                document.body.appendChild(banner);
            }, 120);
        },
    };
</script>
