@foreach(config('pwax.scripts', []) as $script)
	<script src="{{ $script }}"></script>
@endforeach

@yield('scripts')
