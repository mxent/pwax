<template>
    <div class="greeting">Hello {{ $name }}</div>
</template>

<script>
    export default {
        data() {
            return { name: @json($name) };
        },
    };
</script>
