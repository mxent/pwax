{{--
    A page whose content arrives over HTTP after it has mounted.

    The point of the demo is what happens on the second visit with DevTools set to offline:
    the document, the runtime, this component and the JSON below it are all in the service
    worker's caches, so the page renders and fills itself in with no network at all.

    `->cacheable()` on the route is what makes that possible for the payload — see
    `workbench/routes/web.php`. The `fetch` is an ordinary one; the worker's runtime cache
    is what answers it offline.
--}}
<template>
    <div class="items">
        <h1>@{{ title }}</h1>
        <p class="items__lede">Fetched after mount, and served from the cache when offline.</p>

        <ul v-if="items.length" class="items__list">
            <li v-for="item in items" :key="item.id" class="items__item">@{{ item.title }}</li>
        </ul>

        <p v-else-if="failed" class="items__empty" role="status">Nothing to show — the request failed.</p>
        <p v-else class="items__empty" role="status">Loading…</p>
    </div>
</template>

<script>
    export default {
        data() {
            return { title: 'Items', items: [], failed: false };
        },

        async mounted() {
            try {
                const response = await fetch('/api/items', { headers: { Accept: 'application/json' } });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                this.items = (await response.json()).items;
            } catch {
                // Offline with nothing cached yet. Saying so beats a spinner that never stops.
                this.failed = true;
            }
        },
    };
</script>

<style scoped>
    .items { max-width: 40rem; margin: 3rem auto; padding: 0 1.5rem; font-family: system-ui, sans-serif; line-height: 1.6; }
    .items h1 { color: #0c83ff; margin: 0 0 .25rem; }
    .items__lede { margin: 0 0 1.5rem; opacity: .7; }
    .items__list { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }
    .items__item { padding: .75rem 1rem; border: 1px solid currentColor; border-radius: .5rem; opacity: .9; }
    .items__empty { opacity: .6; }
</style>
