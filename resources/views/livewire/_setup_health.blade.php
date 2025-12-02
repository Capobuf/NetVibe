<div class="space-y-4">
    @php
        $allPassed = true;
    @endphp

    @foreach($results as $groupName => $checks)
        <div class="rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5">
                <h3 class="text-sm font-medium text-gray-900 dark:text-white">{{ $groupName }}</h3>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach($checks as $check)
                    @php
                        if ($check['status'] !== 'ok') $allPassed = false;
                        // User requested to treat errors as warnings/info for debugging
                        $isOk = $check['status'] === 'ok';
                        $icon = $isOk ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle';
                        $color = $isOk ? 'text-success-500' : 'text-warning-500';
                        $bgColor = $isOk ? '' : 'bg-warning-50 dark:bg-warning-500/10';
                    @endphp
                    <div x-data="{ open: false }" class="px-4 py-3 {{ $bgColor }}">
                        <div class="flex items-center justify-between cursor-pointer" @click="open = !open">
                            <div class="flex items-center gap-3">
                                <x-filament::icon
                                    :icon="$icon"
                                    class="h-5 w-5 {{ $color }}"
                                />
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $check['name'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $check['message'] }}
                                        @if(!$isOk) <span class="text-xs underline ml-2">View Details</span> @endif
                                    </p>
                                </div>
                            </div>
                            @if(!$isOk)
                                <x-filament::icon :icon="'heroicon-m-chevron-down'" class="h-4 w-4 text-gray-400 transition-transform" x-bind:class="{ 'rotate-180': open }" />
                            @endif
                        </div>
                        @if(!$isOk)
                            <div x-show="open" class="mt-2 pl-8 text-xs text-gray-600 dark:text-gray-300 font-mono bg-black/5 dark:bg-black/30 p-2 rounded">
                                {{ $check['details'] ?? 'No additional details available.' }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    @if(!$allPassed)
        <div class="rounded-md bg-danger-50 p-4 dark:bg-danger-500/10">
            <div class="flex">
                <div class="flex-shrink-0">
                    <x-filament::icon icon="heroicon-m-x-circle" class="h-5 w-5 text-danger-400" />
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-danger-800 dark:text-danger-200">System requirements not met</h3>
                    <div class="mt-2 text-sm text-danger-700 dark:text-danger-300">
                        <p>Please resolve the issues marked with a red cross before proceeding.</p>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
