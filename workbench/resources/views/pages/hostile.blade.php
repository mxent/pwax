{{--
    What a document is not allowed to do.

    Every prop below is one a hostile — or merely careless — document might carry, and
    none of them may reach the DOM. The page checks its own work after mount and prints
    a verdict, so this is a manual walkthrough that fails loudly rather than one you
    have to read the console to trust.

    Open the console alongside it: each dropped prop logs one line naming the element
    and the prop, once, not once per render.
--}}
<template>
    <div class="hostile">
        <h1>@{{ heading }}</h1>
        <p class="hostile__lede">
            The document below sets an inline handler, three markup sinks, two of Vue's
            own prop prefixes, a <code>javascript:</code> URL and another one buried
            inside a list. Nothing it sets should appear in the page.
        </p>

        <p>
            <router-link to="/" id="to-home">Home</router-link>
        </p>

        <div ref="scope">
            <PwaxJson :json="doc" />
        </div>

        <p class="hostile__verdict" :class="'hostile__verdict--' + (failures.length ? 'bad' : 'good')" role="status">
            @{{ verdict }}
        </p>

        <ul v-if="failures.length" class="hostile__failures">
            <li v-for="failure in failures" :key="failure">@{{ failure }}</li>
        </ul>
    </div>
</template>

<script>
    export default {
        data() {
            return {
                heading: 'What a document cannot do',
                // `?? null` so `pwax:compile` can render this view with no data to
                // extract its template. See the note in the README on precompiling a
                // page that renders a document.
                doc: @json($doc ?? null),
                failures: [],
                verdict: 'Checking…',
            };
        },

        mounted() {
            // After the renderer bundle has loaded and the catalog components with it.
            // Nothing here is a Vue update; it is the rendered DOM, which is the only
            // thing that actually answers the question.
            setTimeout(this.audit, 1200);
        },

        methods: {
            audit() {
                const scope = this.$refs.scope;
                const failures = [];

                for (const el of scope.querySelectorAll('*')) {
                    for (const attr of el.attributes) {
                        if (/^on/i.test(attr.name)) {
                            failures.push(`${el.tagName.toLowerCase()} kept ${attr.name}`);
                        }

                        if (/^\s*(javascript|vbscript):/i.test(attr.value)) {
                            failures.push(`${el.tagName.toLowerCase()} kept a script URL`);
                        }
                    }
                }

                if (scope.querySelector('[data-owned]')) {
                    failures.push('markup from the document was parsed into the page');
                }

                if (window.__owned) {
                    failures.push('the document ran script');
                }

                this.failures = failures;
                this.verdict = failures.length
                    ? `${failures.length} thing(s) got through.`
                    : 'Nothing got through. Every dropped prop is named in the console.';
            },
        },
    };
</script>

<style scoped>
    .hostile { max-width: 40rem; margin: 3rem auto; padding: 0 1.5rem; font-family: system-ui, sans-serif; line-height: 1.6; }
    .hostile h1 { color: #0c83ff; margin: 0 0 .25rem; }
    .hostile__lede { margin: 0 0 1.5rem; opacity: .7; }
    .hostile__verdict { margin-top: 1.5rem; padding: .6rem .9rem; border-radius: .5rem; font-weight: 600; }
    .hostile__verdict--good { background: #e7f7ed; color: #16653a; }
    .hostile__verdict--bad { background: #fdeaea; color: #8c1c1c; }
    .hostile__failures { color: #8c1c1c; }
</style>
