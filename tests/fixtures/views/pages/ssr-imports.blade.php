<template>
    <div class="imports">
        <h1>Imports</h1>
        <Badge />
        <Pill />
    </div>
</template>

<script>
    export default {
        components: {
            Badge: @pwaxImport('components.badge'),
            Pill: @pwaxImport('Pill from components.badge'),
        },
    };
</script>
