{{--
    Every expression and element key json-render defines, on one page.

    `/sample` is the readable introduction; this is the reference — if a document can
    say it, it is demonstrated here, so a change to the renderer or to the bridge has
    somewhere visible to break.

    `:functions` supplies what `$computed` calls. It is a prop rather than configuration
    because it is JavaScript, and `config/pwax.php` carries data the runtime reads, never
    code it runs.
--}}
<template>
    <div class="vocab">
        <h1>The document vocabulary</h1>
        <p class="vocab__lede">
            One JSON document using every expression and element key. Nothing below this
            line is a Blade template.
        </p>

        <p><router-link to="/" id="to-home">Home</router-link></p>

        <PwaxJson
            :json="doc"
            :functions="{ initials: ({ name }) => String(name).split(' ').map((p) => p[0]).join('') }"
            @action="onAction"
            @state-change="onStateChange"
        />

        <p class="vocab__log" role="status">@{{ log }}</p>
    </div>
</template>

<script>
    export default {
        data() {
            return { // `?? null` so `pwax:compile` can render this view with no data to
                // extract its template. See the note in the README on precompiling a
                // page that renders a document.
                doc: @json($doc ?? null), log: 'Nothing yet.' };
        },

        methods: {
            onAction(name, params) {
                this.log = `action: ${name} ${JSON.stringify(params ?? {})}`;
            },

            onStateChange(changes) {
                this.log = `state: ${changes.map((c) => c.path).join(', ')}`;
            },
        },
    };
</script>

<style scoped>
    .vocab { max-width: 42rem; margin: 3rem auto; padding: 0 1.5rem; font-family: system-ui, sans-serif; line-height: 1.6; }
    .vocab h1 { color: #0c83ff; margin: 0 0 .25rem; }
    .vocab__lede { margin: 0 0 1.5rem; opacity: .7; }
    .vocab__log { margin-top: 1rem; font-family: ui-monospace, monospace; font-size: .85rem; opacity: .7; }
</style>
