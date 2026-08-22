{{--
    A page that never becomes stable: a clock, a carousel, a poller. Settle mode abandons it
    at the ceiling and serialises whatever has rendered, which is the right outcome — and an
    invisible one, so the bridge reports that it gave up and the PHP side logs it in debug.
--}}
<template>
    <div id="tick">@{{ n }}</div>
</template>

<script>
    export default {
        data() {
            return { n: 0 };
        },
        mounted() {
            setInterval(() => {
                this.n += 1;
            }, 20);
        },
    };
</script>
