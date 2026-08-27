{{--
    A page that is half hand-written Vue and half JSON document.

    That mixture is the point. `pwaxRender()` is unchanged and this is an ordinary Pwax
    component — the heading, the counter and the layout are written the way every other
    page in this demo is written. `<PwaxJson>` renders a document the controller built,
    against the catalog in `workbench/config/pwax.php`.

    Worth watching in devtools on a cold load: `pwax-json.js` is fetched exactly once,
    for this page and no other. The route marks itself `->cacheable()`, so on the second
    visit the whole thing — document, renderer and catalog components — comes off disk
    with the network switched off.
--}}
<template>
    <div class="sample">
        <h1>@{{ heading }}</h1>
        <p class="sample__lede">
            Everything above this line is a Blade template. Everything below it is a JSON
            document rendered against the catalog.
        </p>

        <p>
            <router-link to="/" id="to-home">Home</router-link>
        </p>

        <PwaxJson :json="doc" @action="onAction" @state-change="onStateChange">
            <template #loading>
                <p class="sample__loading" role="status">Loading the renderer…</p>
            </template>
        </PwaxJson>

        <p class="sample__log" role="status">@{{ log }}</p>
    </div>
</template>

<script>
    export default {
        data() {
            return {
                heading: 'A JSON document',
                doc: @json($doc),
                log: 'No action yet.',
            };
        },

        methods: {
            onAction(name, params) {
                this.log = `action: ${name} ${JSON.stringify(params ?? {})}`;
            },

            onStateChange(changes) {
                // The renderer emits the changed pointers rather than a snapshot, which is
                // what makes this cheap enough to listen to on every keystroke.
                this.log = `state: ${changes.map((c) => c.path).join(', ')}`;
            },
        },
    };
</script>

<style scoped>
    .sample { max-width: 40rem; margin: 3rem auto; padding: 0 1.5rem; font-family: system-ui, sans-serif; line-height: 1.6; }
    .sample h1 { color: #0c83ff; margin: 0 0 .25rem; }
    .sample__lede { margin: 0 0 1.5rem; opacity: .7; }
    .sample__loading { opacity: .6; }
    .sample__log { margin-top: 1rem; font-family: ui-monospace, monospace; font-size: .85rem; opacity: .7; }
</style>
