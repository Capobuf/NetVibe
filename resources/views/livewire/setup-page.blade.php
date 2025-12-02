<div>
    @if($setupCompleted)
        <div class="bg-slate-900 p-8 border border-slate-700 rounded-lg text-center">
            <div class="text-6xl mb-4">🎉</div>
            <h2 class="text-2xl font-bold text-white mb-2">Setup Complete!</h2>
            <p class="text-slate-300 mb-6">NetVibe has been successfully installed and configured.</p>
            
            <a href="/admin/login" class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Go to Login
            </a>
        </div>
    @else
        <div class="mb-8 text-center">
            <div class="text-4xl mb-2">🎉</div>
            <h1 class="text-3xl font-bold text-white">Welcome to NetVibe</h1>
            <p class="text-slate-400 mt-2">Let's get your system up and running in a few steps.</p>
        </div>

        <div class="bg-slate-900 p-4 border border-slate-700 rounded-lg">
            {{-- Render the Filament form (Wizard) --}}
            <form wire:submit.prevent="finishSetup">
                {{ $this->form }}
            </form>
        </div>
    @endif
</div>
