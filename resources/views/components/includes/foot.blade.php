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
    global `Vue` at load time. None of them are ES modules, so `defer` would reorder
    them relative to this file's own execution and is deliberately not used.
--}}
@foreach ($shell->vendorScripts() as $script)
    <script {{ $shell->attributes($script) }}></script>
@endforeach

@if (config('pwax.blade.foot'))
    @include(config('pwax.blade.foot'))
@endif
