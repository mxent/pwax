<script src="{{ route('pwax.dist.js', 'vue') }}"></script>
<script src="{{ route('pwax.dist.js', 'vue-router') }}"></script>
<script src="{{ route('pwax.dist.js', 'pinia') }}"></script>

@if (config('pwax.db.enable'))
    <script src="{{ route('pwax.dist.js', 'dexie') }}"></script>
@endif

@foreach (config('pwax.scripts', []) as $script)
    <script src="{{ $script }}"></script>
@endforeach

<script src="{{ url(config('pwax.route_prefix') . '/pwax__x__js_x_main.js') }}"></script>

@if(config('pwax.blade.foot'))
    @include(config('pwax.blade.foot'))
@endif
