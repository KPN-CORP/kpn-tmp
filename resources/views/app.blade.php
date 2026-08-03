<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />

        {{-- Read by app.js for the client-side document title. --}}
        <meta name="app-name" content="{{ config('app.name', 'KPN') }}" />

        <title inertia>{{ config('app.name', 'KPN') }}</title>

        {{-- Inter, to match the design tokens in resources/css/app.css. --}}
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link
            href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap"
            rel="stylesheet"
        />

        @vite('resources/js/app.js')
        <x-inertia::head />
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
