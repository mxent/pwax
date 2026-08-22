<template>
    <div class="cond">
        <p v-if="show">Shown</p>
        <p v-else>Hidden</p>
        <ul><li v-for="n in items" :key="n">@{{ n }}</li></ul>
    </div>
</template>

<script>
    export default {
        data() {
            return { show: true, items: [1, 2, 3] };
        },
    };
</script>
