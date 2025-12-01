<div>
    <div class="mb-6">
        <p class="text-slate-300">Follow the setup wizard to validate your system and configure the database.</p>
    </div>

    <div class="bg-slate-900 p-4 border border-slate-700 rounded">
        {{-- Render the Filament form (Wizard) --}}
        {{ $this->form }}
    </div>

    <div class="mt-6 space-y-3">
        <div class="p-4 bg-slate-800 rounded border border-slate-700">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="text-sm text-slate-300 font-semibold">Database connection</div>
                    <div class="text-xs text-slate-400">Status: <span class="font-mono text-sm">{{ $dbTestStatus }}</span></div>
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="testConnection" class="px-3 py-1 rounded bg-slate-700 text-slate-100 text-sm border border-slate-600 hover:bg-slate-600">Test Connection</button>
                    <button wire:click="runMigrations" class="px-3 py-1 rounded bg-green-600 text-white text-sm hover:bg-green-500" @if($migrating) disabled @endif>
                        @if($migrating) Running... @else Run Migrations @endif
                    </button>
                </div>
            </div>
            @if(str_contains($dbTestStatus ?? '', 'cannot connect'))
                <div class="mt-3 text-sm text-yellow-400">Database connection failed — check your docker-compose.yml and DB credentials.</div>
            @endif
        </div>
    </div>

    <div class="mt-6 p-4 bg-slate-800 rounded border border-slate-700 flex items-center justify-between">
        <div>
            <div class="text-sm text-slate-300 font-semibold">Finish Setup</div>
            <div class="text-xs text-slate-400">Creates the first admin account, saves basic site settings and optionally installs demo data.</div>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="finishSetup" class="px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-500">Finish & Create Admin</button>
        </div>
    </div>
</div>
