{{-- A page that renders a JSON document, so the preload hint has something to fire on. --}}
<template>
    <div>
        <h1>Report</h1>
        <PwaxJson :json="doc" />
    </div>
</template>

<script>
    export default {
        data() {
            return { doc: { root: 'a', elements: { a: { type: 'Card', props: {} } } } };
        },
    };
</script>
