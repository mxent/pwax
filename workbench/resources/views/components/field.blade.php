{{--
    A catalog component with two-way binding.

    `update:modelValue` is what a document's `$bindState` writes through: the runtime
    reads `bindings` from the renderer and turns it into this event, so typing here
    updates the state that a `$template` elsewhere in the document is reading.
--}}
<template>
    <label class="field">
        <span class="field__label">@{{ label }}</span>
        <input
            class="field__input"
            :value="modelValue"
            @input="$emit('update:modelValue', $event.target.value)"
        >
    </label>
</template>

<script>
    export default {
        props: {
            label: { type: String, default: '' },
            modelValue: { type: String, default: '' },
        },
        emits: ['update:modelValue'],
    };
</script>

<style scoped>
    .field { display: grid; gap: .25rem; }
    .field__label { font-size: .85rem; opacity: .7; }
    .field__input { padding: .4rem .6rem; border: 1px solid #d8dee6; border-radius: .4rem; font: inherit; }
</style>
