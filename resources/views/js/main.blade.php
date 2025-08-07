<script>

    const pwaxHeaders = new Headers();
    pwaxHeaders.append("Accept", "application/json");
    pwaxHeaders.append("X-Requested-With", "XMLHttpRequest");
    pwaxHeaders.append("X-Pwa-Component", "true");
    window.pwaxHeaders = pwaxHeaders;
    window.pwaxFetch = async function (url, options = {}) {
        const f = await fetch(url, options);
        const j = await f.json();
        const s = j.script ? await import(`data:text/javascript;base64,${btoa(j.script)}`) : {};
        const e = s?.default || {};
        const v = j.template ? { template: j.template, ...e } : e;
        return {
            s: s,
            v: v
        };
    };
    window.pwaxImports = {};
    window.pwaxImport = async function (url, name, key = '') {
        return window.pwaxImports[name] = window.pwaxImports[name] || await (async function() {
            var r = await window.pwaxFetch(url, {
                headers: window.pwaxHeaders
            });
            return key.length ? r.s[key] : r.v;
        })();
    };

    document.addEventListener('DOMContentLoaded', async function() {

        const baseComp = @import('~pwax/components/vue/app');
        window.app = Vue.createApp(baseComp);

        const router = @import('~pwax/components/vue/router');
        app.use(router);

        const pinia = Pinia.createPinia();
        app.use(pinia);

        const db = @import('~pwax/components/vue/db');
        app.use(db);

        @foreach (config('pwax.plugins', []) as $pluginKey => $pluginInit)
            @if (Illuminate\Support\Str::startsWith($pluginInit, '@import'))
                @php
                    preg_match('/@import\((.*)\)/', $pluginInit, $matches);
                    $importPath = str_replace(['"', "'"], '', $matches[1] ?? '');
                @endphp
                const {{ $pluginKey }}Plugin = {!! import($importPath) !!};
            @else
                const {{ $pluginKey }}Plugin = {!! $pluginInit !!};
            @endif
            app.use({{ $pluginKey }}Plugin);
        @endforeach

        @foreach (config('pwax.directives', []) as $directiveKey => $directiveInit)
            @if (Illuminate\Support\Str::startsWith($directiveInit, '@import'))
                @php
                    preg_match('/@import\((.*)\)/', $directiveInit, $matches);
                    $importPath = str_replace(['"', "'"], '', $matches[1] ?? '');
                @endphp
                const {{ $directiveKey }}Directive = {!! import($importPath) !!};
            @else
                const {{ $directiveKey }}Directive = {!! $directiveInit !!};
            @endif
            app.directive('{{ $directiveKey }}', {{ $directiveKey }}Directive);
        @endforeach

        app.mount('#app');

        document.getElementById('app').classList.remove('preloader');

    });
</script>
