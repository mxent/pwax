@props(['shell', 'initial' => null])
@php
    $nonce = $shell->nonce();
    $nonceAttr = $nonce ? ' nonce="' . e($nonce) . '"' : '';
@endphp
{{--
    Runtime configuration.

    Everything the server needs to tell the client lives here as JSON, which is why
    dist/pwax.js can be a static, long-cached file rather than something generated per
    application. `JSON_HEX_TAG` makes a `</script>` sequence unrepresentable, so no
    configured value can break out of this block.
--}}
<script type="application/json" id="pwax-config"{!! $nonceAttr !!}>
    {!! json_encode(
        $shell->runtimeConfig(),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
    ) !!}
</script>

@if ($initial)
    {{-- The component for this URL, so the first render costs no request. --}}
    <script type="application/json" id="pwax-initial"{!! $nonceAttr !!}>{!! $initial !!}</script>
@endif

{{--
    Vue must evaluate before Vue Router and Pinia: both are IIFE builds that read the
    global `Vue` at load time.

    No `defer`, and not because it would break that order — `defer` preserves document
    order for classic scripts, so it would hold. The reason is that it would buy nothing
    and cost something. These tags sit at the end of `<body>`, with only the closing tags
    below them, and the head already emits `<link rel="preload">` for each so the
    downloads start while it is still being parsed. What `defer` would change is that
    anything an application puts in `pwax.blade.foot` or `@stack('pwax-foot')` — both of
    which render *after* this — would begin running before Vue rather than after it.
--}}
@foreach ($shell->vendorScripts() as $script)
    <script {{ $shell->attributes($script) }}></script>
@endforeach

@if (config('pwax.blade.foot'))
    @include(config('pwax.blade.foot'))
@endif
