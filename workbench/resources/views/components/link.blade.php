{{--
    A catalog component that renders a prop as a URL.

    Ordinary, and that is the point: `<a :href>` is a thing a component does, and it is
    the sink a document reaches with a `javascript:` value. `/hostile` names this
    component to check that the value never lands.
--}}
<template>
    <a class="link" :href="href">@{{ label }}</a>
</template>

<script>
    export default {
        props: {
            href: { type: String, default: undefined },
            label: { type: String, default: 'Open' },
        },
    };
</script>

<style scoped>
    .link { color: #0c83ff; }
</style>
