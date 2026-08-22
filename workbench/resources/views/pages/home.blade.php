{{--
    The demo's first page.

    `badge` is held in `data()` rather than in `components:`, which is the shape
    `<component :is>` needs — and the shape that has to survive a prerender: the SSR state
    island is JSON, so the component is deliberately left out of it and the client builds
    its own. If this renders after a hard reload with SSR on, that path works.
--}}
<template>
    <div class="page">
        <h1>@{{ heading }}</h1>
        <p>@{{ blurb }}</p>

        <p>
            <router-link to="/about" id="to-about">About</router-link> &middot;
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
                blurb: 'Rendered on the server when SSR is on, then hydrated in place.',
                count: 0,
            };
        },
    };
</script>

<style scoped>
    .page { max-width: 40rem; margin: 3rem auto; padding: 0 1.5rem; font-family: system-ui, sans-serif; line-height: 1.6; }
    h1 { color: #0c83ff; }
</style>
