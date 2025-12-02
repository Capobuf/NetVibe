<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NetVibe Setup</title>
    
    @filamentStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-gray-950 text-gray-50">
    
    <main class="flex items-center justify-center min-h-screen p-4">
        <div class="w-full max-w-4xl">
            {{ $slot }}
        </div>
    </main>

    @filamentScripts
</body>
</html>
