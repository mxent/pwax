{{--
    A leaf for the vocabulary demo: renders whatever value an expression resolved to,
    of whatever type, so `$index` and `$cond` have somewhere to land.
--}}
<template>
    <p class="text" :class="tone ? 'text--' + tone : ''">@{{ value }}</p>
</template>

<script>
    export default {
        props: {
            value: { type: [String, Number, Boolean], default: '' },
            tone: { type: String, default: '' },
        },
    };
</script>

<style scoped>
    .text { margin: 0; }
    .text--quiet { opacity: .6; font-size: .9rem; }
    .text--loud { font-weight: 600; color: #0c83ff; }
</style>
