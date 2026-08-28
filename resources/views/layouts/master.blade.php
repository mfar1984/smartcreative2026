<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>@yield('title') - Smart Digital Creative</title>

    @include('partials.favicon')

    {{-- For meta tags a page needs in the head, such as a product's search
         description. Kept separate from `styles` so a meta tag is not pushed into a
         stack named after stylesheets. --}}
    @stack('head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-white">
    @include('components.top-header')
    @include('components.header')
    
    @yield('content')
    
    @include('components.footer')
    
    @stack('scripts')
</body>
</html>
