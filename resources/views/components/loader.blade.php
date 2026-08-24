{{--
    Shown only when there is no page on screen yet — the first paint of an application
    whose landing page was not inlined, or a retry after an error.

    It is deliberately not what you see between two pages. Navigating no longer unmounts
    the page you are on: the current one stays rendered until its replacement is ready,
    and the progress bar at the top of the window is the only thing that moves. Replacing
    a page with the word "Loading" threw away what the visitor was reading and collapsed
    the layout to a single line, twice per navigation.

    So this stays silent visually and speaks only to assistive technology, which has no
    progress bar to look at. Override with `pwax.blade.loader` if you would rather show a
    skeleton here.

    Rendered by Vue, so Vue syntax applies — escape Blade's own interpolation as `@{{ }}`.
--}}
<div class="pwax-loading" role="status">
    <span class="pwax-sr-only">Loading</span>
</div>
