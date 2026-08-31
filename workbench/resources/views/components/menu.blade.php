{{--
    A catalog component whose prop is a list of links.

    The shape that made a top-level-only value check useless: the URL a document wants to
    smuggle in is not the prop, it is a field of an entry inside the prop. `/hostile`
    names this component to check that the whole prop is refused.
--}}
<template>
    <nav class="menu">
        <a v-for="item in items" :key="item.label" class="menu__link" :href="item.href">
            @{{ item.label }}
        </a>
    </nav>
</template>

<script>
    export default {
        props: {
            items: { type: Array, default: () => [] },
        },
    };
</script>

<style scoped>
    .menu { display: flex; gap: .75rem; }
    .menu__link { color: #0c83ff; }
</style>
