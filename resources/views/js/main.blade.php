@php
    $swEnabled = (bool) config('pwax.service_worker.enabled', false);
    $swPath = config('pwax.service_worker.path', '/service-worker.js');
@endphp
<script>
    (function () {
        const pwaxHeaders = new Headers();
        pwaxHeaders.append('Accept', 'application/json');
        pwaxHeaders.append('X-Requested-With', 'XMLHttpRequest');
        pwaxHeaders.append('X-Pwa-Component', 'true');

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta && csrfMeta.getAttribute('content')) {
            pwaxHeaders.append('X-CSRF-TOKEN', csrfMeta.getAttribute('content'));
        }

        window.pwaxHeaders = pwaxHeaders;

        window.pwaxInjectStyles = function (name, json) {
            // Inject inline style into document head
            if (json.style) {
                const styleId = 'pwax-style-' + name;
                if (!document.getElementById(styleId)) {
                    const inlineStyle = document.createElement('style');
                    inlineStyle.id = styleId;
                    inlineStyle.innerHTML = json.style;
                    inlineStyle.setAttribute('pwax-attached', '');
                    document.getElementsByTagName('head')[0].appendChild(inlineStyle);
                }
            }

            // Load external stylesheets
            if (json.styles && json.styles.length) {
                const headTag = document.getElementsByTagName('head')[0];
                const loadedHrefs = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map((l) => l.href);
                json.styles.forEach((href) => {
                    if (!loadedHrefs.includes(href)) {
                        const link = document.createElement('link');
                        link.rel = 'stylesheet';
                        link.href = href;
                        link.setAttribute('pwax-attached', '');
                        headTag.appendChild(link);
                    }
                });
            }
        };

        window.pwaxFetch = async function (url, options = {}) {
            const { withCredentials = true, ...fetchOptions } = options;

            const response = await fetch(url, {
                ...fetchOptions,
                credentials: withCredentials ? 'include' : 'same-origin',
                headers: {
                    ...(fetchOptions.headers || {}),
                    ...Object.fromEntries(window.pwaxHeaders.entries()),
                },
            });

            if (!response.ok) {
                throw new Error('pwaxFetch: HTTP ' + response.status);
            }

            return await response.json();
        };

        window.pwaxImportCache = {};

        window.pwaxImport = async function (url, name, key = '') {
            if (window.pwaxImportCache[name]) {
                Object.keys(window.pwaxImportCache).forEach((n) => {
                    window.pwaxInjectStyles(n, window.pwaxImportCache[n].json);
                });
                return window.pwaxImportCache[name].component;
            }

            const json = await window.pwaxFetch(url, { headers: window.pwaxHeaders });
            window.pwaxInjectStyles(name, json);

            // Pre-cache a placeholder BEFORE compiling so circular imports
            // (Element ↔ Card) find a cache entry immediately. After compilation
            // the placeholder is mutated in-place to add methods/computed, so
            // all existing references (from sub-imports) automatically pick them up.
            const placeholder = { template: json.template };
            window.pwaxImportCache[name] = { component: placeholder, json };

            const scriptSource = json.script || '';
            let module = {};
            if (scriptSource) {
                const dataUrl = `data:text/javascript;base64,${btoa(unescape(encodeURIComponent(scriptSource)))}`;
                module = await import(dataUrl);
            }
            const realComponent = key && key.length
                ? module[key]
                : { template: json.template, ...(module.default || {}) };

            // Mutate the placeholder in-place — all references from circular
            // sub-imports now see the full component with methods/computed.
            Object.assign(placeholder, realComponent);
            return placeholder;
        };
    })();

    document.addEventListener('DOMContentLoaded', async function () {
        try {
            const baseComp = @import('pwax::components.vue.app');
            window.app = Vue.createApp(baseComp);

            const router = @import('pwax::components.vue.router');
            window.app.use(router);

            if (typeof Pinia !== 'undefined' && Pinia.createPinia) {
                window.app.use(Pinia.createPinia());
            }

            @foreach (config('pwax.plugins', []) as $pluginKey => $pluginInit)
                @php
                    $pluginKey = Illuminate\Support\Str::studly($pluginKey);
                @endphp
                @if (Illuminate\Support\Str::startsWith($pluginInit, '@import'))
                    @php
                        preg_match('/@import\((.*)\)/', $pluginInit, $matches);
                        $importPath = str_replace(['"', "'"], '', $matches[1] ?? '');
                    @endphp
                    const {{ $pluginKey }}Plugin = {!! import($importPath) !!};
                @else
                    const {{ $pluginKey }}Plugin = {!! $pluginInit !!};
                @endif
                window.app.use({{ $pluginKey }}Plugin);
            @endforeach

            @foreach (config('pwax.directives', []) as $directiveKey => $directiveInit)
                @php
                    $directiveKey = Illuminate\Support\Str::studly($directiveKey);
                @endphp
                @if (Illuminate\Support\Str::startsWith($directiveInit, '@import'))
                    @php
                        preg_match('/@import\((.*)\)/', $directiveInit, $matches);
                        $importPath = str_replace(['"', "'"], '', $matches[1] ?? '');
                    @endphp
                    const {{ $directiveKey }}Directive = {!! import($importPath) !!};
                @else
                    const {{ $directiveKey }}Directive = {!! $directiveInit !!};
                @endif
                window.app.directive('{{ $directiveKey }}', {{ $directiveKey }}Directive);
            @endforeach

            const mountEl = document.getElementById('pwax');
            if (mountEl) {
                window.app.mount('#pwax');
                mountEl.classList.remove('preloader');
            } else {
                console.error('pwax: mount element #pwax not found');
            }
        } catch (err) {
            console.error('pwax: bootstrap failed', err);
            const mountEl = document.getElementById('pwax');
            if (mountEl) {
                mountEl.classList.remove('preloader');
                mountEl.innerHTML = '<div class="pwax-error">Failed to start the application.</div>';
            }
        }

        @if ($swEnabled)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register(@json($swPath), { scope: '/' })
                    .catch(function (e) { console.warn('pwax: service worker registration failed', e); });
            });
        }
        @endif
    });
</script>
