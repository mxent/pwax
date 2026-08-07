{{--
    Shown when a page fails to load. Rendered by Vue, with `error` in scope:
    `error.status`, `error.statusText`, `error.message`. `retry()` refetches.

    Override with `pwax.blade.error` in config/pwax.php.

    NOTE: `v-text`, not `v-html`. Part of `error` derives from the response, and
    rendering that as HTML would turn any reflected content into markup.
--}}
<div class="pwax-error" role="alert">
    <h1 v-text="error.statusText"></h1>
    <p v-text="error.message"></p>

    <button type="button" class="pwax-retry" @click="retry">
        Try again
    </button>

    <p>
        <a href="{{ app(\Mxent\Pwax\Pwax::class)->homeUrl() }}">Return home</a>
    </p>
</div>
