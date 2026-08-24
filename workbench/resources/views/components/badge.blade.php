{{-- A component imported with @pwaxImport, so the demo exercises that path too. --}}
<template>
    <span class="badge">@{{ label }}</span>
</template>

<script>
    export default {
        props: {
            label: { type: String, default: '' },
        },
    };
</script>

<style scoped>
    .badge { display: inline-block; margin-left: .5rem; padding: .1rem .5rem; border-radius: .5rem; background: #0c83ff; color: #fff; font-size: .8rem; }
</style>
