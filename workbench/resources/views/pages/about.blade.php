{{--
    The second page. Its <style scoped> block is what makes the A -> B -> A check in
    CONTRIBUTING.md meaningful: walk between the two and the document must never hold more
    than one `<style data-pwax-style>` per component that is still mounted.
--}}
<template>
    <div class="page">
        <h1>About</h1>
        <p>A second page, so there is somewhere to navigate to and back from.</p>
        <p><router-link to="/" id="to-home">Home</router-link></p>
    </div>
</template>

<script>
    export default {
        data() {
            return {};
        },
    };
</script>

<style scoped>
    .page { max-width: 40rem; margin: 3rem auto; padding: 0 1.5rem; font-family: system-ui, sans-serif; line-height: 1.6; }
    h1 { color: rebeccapurple; }
</style>
