{{--
    The demo's first page.

    `badge` is held in `data()` rather than registered in `components:`. Both work —
    `@pwaxImport` yields a Vue async component either way — and this is the shape worth
    exercising here, because `<component :is>` is what an application reaches for when the
    component to render is decided at runtime.
--}}
<template>
    <div class="page">
        <h1>@{{ heading }}</h1>
        <p>@{{ blurb }}</p>

        <p>
            <router-link to="/about" id="to-about">About</router-link> &middot;
            <router-link to="/items" id="to-items">Items</router-link> &middot;
            <a href="/elsewhere" id="to-elsewhere">A route that redirects</a>
        </p>

        <p>
            <button type="button" id="bump" @click="count++">Clicked @{{ count }} times</button>
            <component :is="badge" label="live" />
        </p>
    </div>
</template>

<script>
    const badge = @pwaxImport('components.badge');

    export default {
        data() {
            return {
                badge,
                heading: @json($heading ?? 'Pwax'),
                blurb: 'Compiled from the payload in this document — no request before first paint.',
                count: 0,
            };
        },
    };
</script>

<style scoped>
    .page { max-width: 40rem; margin: 3rem auto; padding: 0 1.5rem; font-family: system-ui, sans-serif; line-height: 1.6; }
    h1 { color: #0c83ff; }
</style>
