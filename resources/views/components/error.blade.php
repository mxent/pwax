<h1>@{{ errResponse.statusText }}</h1>
<p
    v-html="errResponse.message || 'Please try to refresh the page.<br/>If the problem persists, please contact the administrator.'">
</p>
<a href="{{ router(config('pwax.home', '/')) }}">
    Return to home
</a>
