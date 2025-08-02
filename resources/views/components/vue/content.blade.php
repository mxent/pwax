@if(config('pwax.blade.content'))
    @include(config('pwax.blade.content'))
@else
    <main>
        <router-view></router-view>
    </main>
@endif
