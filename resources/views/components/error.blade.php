{{--
    Shown when a page fails to load. Rendered by Vue, with `error` in scope:
    `error.status`, `error.statusText`, `error.message`. `retry()` refetches.

    Override with `pwax.blade.error` in config/pwax.php, or restyle without publishing
    anything by setting the `--pwax-screen-*` custom properties in your own stylesheet.

    NOTE: `v-text`, not `v-html`. Part of `error` derives from the response, and rendering
    that as HTML would turn any reflected content into markup.
--}}
<div class="pwax-screen pwax-error" role="alert">
    <div class="pwax-screen__panel">
        <p class="pwax-screen__code" v-text="error.status"></p>

        <h1 class="pwax-screen__title" v-text="error.statusText"></h1>
        <p class="pwax-screen__message" v-text="error.message"></p>

        <div class="pwax-screen__actions">
            {{--
                First, and a button rather than a link: most of what lands here is a
                connection that dropped for a moment, and retrying costs one request
                against reloading the whole application.
            --}}
            <button type="button" class="pwax-button pwax-retry" @click="retry">
                Try again
            </button>

            <a class="pwax-button pwax-button--quiet" href="{{ app(\Mxent\Pwax\Pwax::class)->homeUrl() }}">
                Return home
            </a>
        </div>
    </div>
</div>
