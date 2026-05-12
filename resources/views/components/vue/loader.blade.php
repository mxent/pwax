<template>
    <template v-if="errResponse">
        <x-pwax::error />
    </template>
    <template v-else-if="loading">
        <x-pwax::loader />
    </template>
    <template v-else>
        <component :is="component"></component>
    </template>
</template>

<script>
    const middlewareRegistry = {};
    @foreach (config('pwax.middleware', []) as $middlewareKey => $middlewareInit)
        @if (Illuminate\Support\Str::startsWith($middlewareInit, '@import'))
            @php
                preg_match('/@import\((.*)\)/', $middlewareInit, $matches);
                $importPath = str_replace(['"', "'"], '', $matches[1] ?? '');
            @endphp
            const {{ $middlewareKey }}Middleware = {!! import($importPath) !!};
        @else
            const {{ $middlewareKey }}Middleware = {!! $middlewareInit !!};
        @endif
        middlewareRegistry['{{ $middlewareKey }}'] = {{ $middlewareKey }}Middleware;
    @endforeach

    export default {
        data() {
            return {
                component: null,
                loading: true,
                errResponse: null,
                currentFetchComponent: null,
                currentPage: '',
            };
        },
        async created() {
            await this.fetchComponent(this.$route.params.page);
        },
        async beforeRouteUpdate(to, from) {
            if (this.currentPage === to.params.page) {
                return;
            }
            document.dispatchEvent(new CustomEvent('routing', { detail: { from, to } }));
            await this.fetchComponent(to.params.page);
        },
        methods: {
            async processComponent(data) {
                const $vue = this;

                document.querySelectorAll('[pwax-attached]').forEach((el) => el.remove());

                const headTag = document.getElementsByTagName('head')[0];
                const loadedScriptsSrcs = Array.from(document.querySelectorAll('script')).map((s) => s.src);
                const loadedStylesHrefs = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map((l) => l.href);

                const externalScripts = (data.scripts || []).filter((src) => !loadedScriptsSrcs.includes(src));
                const externalStyles = (data.styles || []).filter((href) => !loadedStylesHrefs.includes(href));

                const loadResource = (tag, attrs) => new Promise((resolve, reject) => {
                    const el = document.createElement(tag);
                    Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
                    el.setAttribute('pwax-attached', '');
                    el.addEventListener('load', () => resolve());
                    el.addEventListener('error', (e) => reject(e));
                    headTag.appendChild(el);
                });

                const scriptPromises = externalScripts.map((src) => loadResource('script', { src }));
                const stylePromises = externalStyles.map((href) => loadResource('link', { rel: 'stylesheet', href }));

                if (data.style) {
                    const inlineStyle = document.createElement('style');
                    inlineStyle.innerHTML = data.style;
                    inlineStyle.setAttribute('pwax-attached', '');
                    headTag.appendChild(inlineStyle);
                }

                try {
                    await Promise.all([...scriptPromises, ...stylePromises]);
                } catch (err) {
                    console.error('pwax: failed to load component assets', err);
                    $vue.errResponse = { status: 'Asset Error', statusText: 'Failed to load component assets.' };
                    $vue.loading = false;
                    return;
                }

                const scriptSource = data.script || 'export default {}';
                const module = await import(`data:text/javascript;base64,${btoa(unescape(encodeURIComponent(scriptSource)))}`);
                const componentOptions = {
                    template: data.template,
                    ...(module.default || {}),
                };

                const middlewareList = componentOptions.middleware || [];
                const meta = componentOptions.meta || {};

                for (const name of middlewareList) {
                    const fn = middlewareRegistry[name];
                    if (typeof fn === 'function') {
                        let redirected = false;
                        await fn({
                            component: componentOptions,
                            meta,
                            redirect: (path) => { redirected = true; $vue.$router.push(path); },
                        });
                        if (redirected) return;
                    }
                }

                const component = Vue.defineAsyncComponent(() => Promise.resolve(componentOptions));
                $vue.component = Vue.shallowRef(component);
                $vue.loading = false;
                $vue.$nextTick(() => {
                    setTimeout(() => {
                        document.dispatchEvent(new CustomEvent('routed', {
                            detail: { component: componentOptions, page: $vue.currentPage },
                        }));
                    }, 0);
                });
            },

            async fetchComponent(p = '') {
                const $vue = this;
                if ($vue.currentFetchComponent) {
                    $vue.currentFetchComponent.abortController.abort();
                }

                $vue.errResponse = null;
                $vue.loading = true;

                const abortController = new AbortController();
                $vue.currentFetchComponent = { abortController };

                let response;
                try {
                    const target = '/' + (p || '').replace(/^\/+/, '');
                    response = await fetch(target, {
                        headers: window.pwaxHeaders,
                        credentials: 'same-origin',
                        signal: abortController.signal,
                    });

                    if (abortController.signal.aborted) return;

                    if (!response.ok) {
                        $vue.errResponse = {
                            status: response.status,
                            statusText: response.statusText || 'Error',
                            message: response.status === 404
                                ? 'The requested page was not found.'
                                : 'An error occurred while loading the page.',
                        };
                        return;
                    }

                    const data = await response.json();

                    if (data.redirect) {
                        $vue.$router.push(data.redirect);
                        return;
                    }

                    $vue.currentPage = p;
                    await $vue.processComponent(data);
                } catch (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    $vue.errResponse = {
                        status: 'Network Error',
                        statusText: 'This page needs an internet connection to load.',
                        message: (error && error.message) || 'Network request failed.',
                    };
                } finally {
                    if ($vue.currentFetchComponent && $vue.currentFetchComponent.abortController === abortController) {
                        $vue.currentFetchComponent = null;
                    }
                    if ($vue.loading && $vue.errResponse) {
                        $vue.loading = false;
                    }
                }
            },
        },
    };
</script>
