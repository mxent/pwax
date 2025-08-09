@foreach (config('pwax.scripts', []) as $script)
    <script src="{{ $script }}"></script>
@endforeach

<script src="{{ route('pwax.js', 'pwax__x__js_x_main') }}"></script>

@if(config('pwax.blade.foot'))
    @include(config('pwax.blade.foot'))
@endif
