{{--
    A catalog component: an ordinary Pwax component that a JSON document may name.

    Nothing here is special. It is declared in `workbench/config/pwax.php` under
    `json.components`, and the one rule that matters is the single default `<slot />` —
    a document lists its children under `children`, and they all arrive there.
--}}
<template>
    <section class="card" :class="'card--' + variant">
        <h2 v-if="title" class="card__title">@{{ title }}</h2>
        <div class="card__body"><slot /></div>
    </section>
</template>

<script>
    export default {
        props: {
            title: { type: String, default: '' },
            variant: { type: String, default: 'plain' },
        },
    };
</script>

<style scoped>
    .card { border: 1px solid #d8dee6; border-radius: .75rem; padding: 1rem 1.25rem; margin: 0 0 1rem; }
    .card--raised { box-shadow: 0 1px 3px rgba(0, 0, 0, .12); border-color: transparent; }
    .card__title { margin: 0 0 .5rem; font-size: 1.1rem; color: #0c83ff; }
    .card__body { display: grid; gap: .5rem; }
</style>
