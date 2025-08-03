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
            let $vue = this;
            await $vue.fetchComponent($vue.$route.params.page);
        },
        async beforeRouteUpdate(to, from) {
            let $vue = this;
            if ($vue.currentPage == to.params.page) {
                return;
            }
            const routingEvent = new CustomEvent('routing', {
                detail: {
                    from: from,
                    to: to,
                }
            });
            document.dispatchEvent(routingEvent);
            await $vue.fetchComponent(to.params.page);
        },
        methods: {
            async processComponent(data) {
                const $vue = this;
                const store = $vue.$store;
                const lazyElements = document.querySelectorAll('[pwax-attached]');
                lazyElements.forEach(function(element) {
                    element.remove();
                });
                const headTag = document.getElementsByTagName('head')[0];
                const loadedScriptsSrcs = Array.from(document.querySelectorAll('script')).map(script => script.src);
                data.scripts = data.scripts.filter(script => !loadedScriptsSrcs.includes(script));
                const scriptPromises = data.scripts.map(function(script) {
                    return new Promise(function(resolve, reject) {
                        const lazyScript = document.createElement('script');
                        lazyScript.src = script;
                        lazyScript.setAttribute('pwax-attached', '');
                        lazyScript.addEventListener('load', function() {
                            resolve();
                        });
                        lazyScript.addEventListener('error', function(error) {
                            reject(error);
                        });
                        headTag.appendChild(lazyScript);
                    });
                });
                const loadedStylesHrefs = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(
                    link => link.href);
                data.styles = data.styles.filter(style => !loadedStylesHrefs.includes(style));
                const stylePromises = data.styles.map(function(cssPath) {
                    return new Promise(function(resolve, reject) {
                        const lazyLink = document.createElement('link');
                        lazyLink.href = cssPath;
                        lazyLink.setAttribute('rel', 'stylesheet');
                        lazyLink.setAttribute('pwax-attached', '');
                        lazyLink.addEventListener('load', function() {
                            resolve();
                        });
                        lazyLink.addEventListener('error', function(error) {
                            reject(error);
                        });
                        headTag.appendChild(lazyLink);
                    });
                });
                const stylePromise = new Promise(function(resolve) {
                    const lazyStyle = document.createElement('style');
                    lazyStyle.innerHTML = data.style;
                    lazyStyle.setAttribute('pwax-attached', '');
                    headTag.appendChild(lazyStyle);
                    resolve();
                });
                Promise.all([...scriptPromises, ...stylePromises, stylePromise]).then(async function() {
                    const module = await import(`data:text/javascript;base64,${btoa(data.script)}`);
                    const componentOptions = {
                        template: data.template,
                        ...module.default,
                    };

                    const middlewareList = componentOptions.middleware || [];
                    const meta = componentOptions.meta || {};

                    for (const name of middlewareList) {
                        const fn = middlewareRegistry[name];
                        if (typeof fn === 'function') {
                            let redirected = false;
                            await fn({
                                component: componentOptions,
                                meta: meta,
                                redirect: (path) => {
                                    redirected = true;
                                    $vue.$router.push(path);
                                }
                            });
                            if (redirected) return;
                        }
                    }

                    var component = await Vue.defineAsyncComponent(function() {
                        return Promise.resolve(componentOptions);
                    });
                    $vue.component = Vue.shallowRef(component);
                    $vue.loading = false;
                    $vue.$nextTick(function() {
                        setTimeout(function() {
                            const routedEvent = new CustomEvent('routed', {
                                detail: {
                                    component: componentOptions,
                                    page: $vue.currentPage,
                                }
                            });
                            document.dispatchEvent(routedEvent);
                        }, 0);
                    });
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
                $vue.currentFetchComponent = {
                    abortController
                };
                let response;
                try {
                    response = await fetch('/' + p, {
                        headers: window.pwaxHeaders,
                        signal: abortController.signal,
                    });

                    if (abortController.signal.aborted) {
                        return;
                    }

                    if (response.status !== 200) {
                        throw new Error(response.status);
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('notOk');
                        return;
                    }

                    const data = await response.json();

                    if (data.redirect) {
                        $vue.$root.$router.push(data.redirect);
                        return;
                    }

                    $vue.currentPage = p;
                    await $vue.processComponent(data);

                } catch (error) {
                    if (error instanceof DOMException) {
                        //
                    } else if (error instanceof Error) {
                        if (error.message === '404') {
                            $vue.errResponse = {
                                status: 404,
                                statusText: 'Not Found',
                                message: 'The requested page was not found.',
                            };
                        } else if (error.message === 'notOk') {
                            $vue.errResponse = response;
                        } else if (!isNaN(error.message)) {
                            $vue.errResponse = {
                                status: error.message,
                                statusText: response.statusText,
                            };
                        } else {
                            $vue.errResponse = {
                                status: 'Network Error',
                                statusText: 'This page needs internet connection to load.',
                            };
                        }
                    } else {
                        $vue.errResponse = {
                            status: 'Error',
                            statusText: 'An error occured while processing your request.',
                        };
                    }
                } finally {
                    if ($vue.currentFetchComponent && $vue.currentFetchComponent.abortController ===
                        abortController) {
                        $vue.currentFetchComponent = null;
                    }
                    $vue.loading = false;
                }
            },
        }
    }
</script>
