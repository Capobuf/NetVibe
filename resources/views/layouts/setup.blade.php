<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>NetVibe Setup</title>
    <!-- Prefer Filament styles if available, fall back to Tailwind CDN for simplicity -->
    @if (file_exists(public_path('build/assets/app.css')))
      <link href="{{ asset('build/assets/app.css') }}" rel="stylesheet">
    @else
      <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    @endif
  </head>
  <body class="min-h-screen bg-slate-900 text-slate-100 leading-normal">
    <div class="min-h-screen flex items-center justify-center p-6">
      <div class="max-w-4xl w-full bg-slate-800 shadow-xl rounded-lg border border-slate-700">
        <header class="px-8 py-6 border-b border-slate-700">
          <h1 class="text-2xl font-semibold">NetVibe Setup</h1>
        </header>

        <main class="p-8">
          {{ $slot }}
        </main>

        <footer class="px-8 py-4 border-t border-slate-700 text-sm text-slate-400">
          &copy; {{ date('Y') }} NetVibe — Initial Setup
        </footer>
      </div>
    </div>
    @stack('scripts')
  </body>
</html>
