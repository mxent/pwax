{{--
    The application's root template. Rendered by Vue, so Vue syntax applies here —
    remember to escape Blade's own interpolation as `@{{ ... }}`.

    Override with `pwax.blade.content` in config/pwax.php.
--}}
<main>
    <router-view></router-view>
</main>
