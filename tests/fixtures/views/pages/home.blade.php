<template>
    <div class="home">
        <h1>@{{ title }}</h1>
    </div>
</template>

<script>
    export default {
        data() {
            return { title: 'Home' };
        },
    };
</script>

<style>
    .home { padding: 1rem; }
</style>
