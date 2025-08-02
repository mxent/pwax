<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <x-pwax::includes.head />
</head>

<body>
    <div id="app" class="preloader">
        @yield('content')
    </div>

    <x-pwax::includes.foot />
</body>

</html>
