<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetVibe Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-2xl">
        <div class="bg-white rounded-lg shadow-xl overflow-hidden">
            <!-- Header -->
            <div class="bg-gradient-to-r from-teal-600 to-cyan-600 px-8 py-6">
                <h1 class="text-3xl font-bold text-white">NetVibe</h1>
                <p class="text-teal-100 mt-1">Setup & Installation Wizard</p>
            </div>

            <!-- Content -->
            <div class="p-8">
                @yield('content')
            </div>

            <!-- Footer -->
            <div class="bg-gray-50 px-8 py-4 border-t border-gray-200">
                <p class="text-sm text-gray-600 text-center">
                    Vibe-Native Network Infrastructure Management
                </p>
            </div>
        </div>
    </div>
</body>
</html>
