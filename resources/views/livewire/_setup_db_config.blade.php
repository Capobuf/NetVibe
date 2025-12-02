<div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            @if($status === 'connected')
                <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 text-success-500" />
                <div>
                    <p class="text-sm font-medium text-success-600 dark:text-success-400">Connected successfully</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Settings saved. You can proceed.</p>
                </div>
            @elseif(str_contains($status ?? '', 'cannot connect'))
                <x-filament::icon icon="heroicon-o-x-circle" class="h-6 w-6 text-danger-500" />
                <div>
                    <p class="text-sm font-medium text-danger-600 dark:text-danger-400">Connection failed</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Check your credentials.</p>
                </div>
            @else
                <x-filament::icon icon="heroicon-o-question-mark-circle" class="h-6 w-6 text-gray-400" />
                <div>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Not tested yet</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Enter credentials and click Test Connection</p>
                </div>
            @endif
        </div>
        
        <x-filament::button
            wire:click="testConnection"
            size="sm"
            :color="$status === 'connected' ? 'success' : 'gray'"
        >
            {{ $status === 'connected' ? 'Test Again' : 'Test Connection & Save' }}
        </x-filament::button>
    </div>

    @if(str_contains($status ?? '', 'cannot connect'))
        <div class="mt-3 p-3 bg-danger-50 dark:bg-danger-500/10 rounded border border-danger-200 dark:border-danger-500/20">
            <p class="text-xs font-mono text-danger-700 dark:text-danger-300 break-all">
                {{ str_replace('cannot connect — ', '', $status) }}
            </p>
        </div>
    @endif
</div>
