<div class="space-y-4">
    <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900 text-center">
        
        @if($success)
            <div class="flex flex-col items-center justify-center gap-4">
                <div class="rounded-full bg-success-100 p-3 dark:bg-success-500/20">
                    <x-filament::icon icon="heroicon-o-check" class="h-8 w-8 text-success-600 dark:text-success-400" />
                </div>
                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">Database Ready</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">All migrations have been executed successfully.</p>
                </div>
            </div>
        @else
            <div class="flex flex-col items-center justify-center gap-4">
                <div class="rounded-full bg-primary-100 p-3 dark:bg-primary-500/20">
                    <x-filament::icon icon="heroicon-o-circle-stack" class="h-8 w-8 text-primary-600 dark:text-primary-400" />
                </div>
                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">Setup Database</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Click below to create the necessary tables in your database.</p>
                </div>
                
                <x-filament::button
                    wire:click="runMigrations"
                    size="lg"
                    :disabled="$migrating"
                >
                    @if($migrating)
                        Running Migrations...
                    @else
                        Run Migrations
                    @endif
                </x-filament::button>
            </div>
        @endif

    </div>
</div>
