{{--
    A catalog component that raises an event.

    `emits` is the whole contract: a document binds `on: { press: … }` and the runtime
    wires it from this declaration. Nothing in `config/pwax.php` repeats the event name.
--}}
<template>
    <button type="button" class="jbutton" :class="'jbutton--' + variant" @click="$emit('press')">
        @{{ label }}
    </button>
</template>

<script>
    export default {
        props: {
            label: { type: String, required: true },
            variant: { type: String, default: 'primary' },
        },
        emits: ['press'],
    };
</script>

<style scoped>
    .jbutton { padding: .4rem .9rem; border: 0; border-radius: .4rem; cursor: pointer; font: inherit; }
    .jbutton--primary { background: #0c83ff; color: #fff; }
    .jbutton--secondary { background: #eef2f7; color: #24303f; }
</style>
