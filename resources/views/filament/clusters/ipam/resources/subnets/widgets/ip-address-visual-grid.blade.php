<x-filament-widgets::widget>
    @if (! $record)
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Subnet not available</div>
        </x-filament::section>
    @elseif (! $renderGrid)
        <x-filament::section>
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-gray-50 dark:bg-gray-800">
                <div class="flex items-start gap-3">
                    <x-filament::icon
                        icon="heroicon-o-information-circle"
                        class="h-5 w-5 text-gray-400"
                    />
                    <div>
                        <div class="font-medium text-sm text-gray-900 dark:text-white">Visual Grid Disabled</div>
                        <div class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                            The visual grid is disabled for large subnets (mask < /22). Use the IP table above to search and manage IP addresses.
                        </div>
                    </div>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            {{-- Header with title on left and legend on right --}}
            <x-slot name="heading">
                <div class="flex items-center justify-between w-full">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-squares-2x2"
                            class="h-5 w-5 text-gray-500 dark:text-gray-400"
                        />
                        <span class="text-sm font-medium">IP Address Visual Map</span>
                        <x-filament::badge color="gray" size="sm">
                            {{ $record->cidr ?? $record->subnet }}
                        </x-filament::badge>
                        <x-filament::badge color="info" size="sm">
                            {{ collect($cells)->where('exists', true)->count() }} / {{ $totalHosts }} used
                        </x-filament::badge>
                    </div>
                    
                    {{-- Legend on the right --}}
                    <div class="flex items-center gap-3 text-xs text-gray-600 dark:text-gray-400">
                        <div class="flex items-center gap-1.5">
                            <x-filament::badge color="success" size="sm">•</x-filament::badge>
                            <span>Active</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <x-filament::badge color="info" size="sm">•</x-filament::badge>
                            <span>Gateway</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <x-filament::badge color="warning" size="sm">•</x-filament::badge>
                            <span>Reserved</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <x-filament::badge color="danger" size="sm">•</x-filament::badge>
                            <span>Offline</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <x-filament::badge color="gray" size="sm">•</x-filament::badge>
                            <span>Available</span>
                        </div>
                    </div>
                </div>
            </x-slot>

            {{-- Table-like grid with borders --}}
            <div class="ip-grid-table">
                @foreach ($cells as $cell)
                    @php
                        $ip = $cell['ip'] ?? null;
                        $lastOctet = \Illuminate\Support\Str::afterLast($ip, '.') ?? $ip;
                        
                        // Determine badge color based on status
                        if ($cell['exists']) {
                            if (($cell['type'] ?? '') === 'gateway') {
                                $color = 'info'; // blue
                            } elseif (($cell['type'] ?? '') === 'reserved') {
                                $color = 'warning'; // orange
                            } elseif (($cell['state'] ?? 0) === 1) {
                                $color = 'danger'; // red
                            } else {
                                $color = 'success'; // green
                            }
                        } else {
                            $color = 'gray';
                        }
                    @endphp

                    <div class="ip-cell">
                        <a 
                            href="{{ $cell['exists'] ? '/admin/ip-addresses/' . $cell['id'] . '/edit' : '/admin/ip-addresses/create?subnet_id=' . $record->getKey() . '&ip_addr=' . urlencode($ip) }}" 
                            title="{{ $ip }}{{ ($cell['exists'] && $cell['hostname']) ? ' - '.$cell['hostname'] : ($cell['exists'] ? '' : ' (Click to create)') }}"
                            class="ip-cell-link"
                        >
                            <x-filament::badge 
                                :color="$color"
                                size="sm"
                                class="w-full h-full flex items-center justify-center font-mono text-[10px]"
                            >
                                {{ $lastOctet }}
                            </x-filament::badge>
                        </a>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>

<style>
    /* Table-like grid layout: make a strict, tabular grid with square cells.
       Using padding-top technique instead of relying solely on aspect-ratio
       improves cross-browser stability and ensures rows align exactly. */
    .ip-grid-table {
        display: grid;
        /* Keep the original 20-column layout but prevent fractional columns
           from creating uneven rows by using minmax(0, 1fr) */
        grid-template-columns: repeat(20, minmax(0, 1fr));
        /* Make rows match column width by using a padding hack on children */
        grid-auto-rows: 1fr;
        border: 1px solid rgb(229 231 235); /* gray-200 */
        border-radius: 0.5rem;
        overflow: hidden;
        background: white;
        align-items: stretch;
        justify-items: stretch;
    }
    
    .dark .ip-grid-table {
        border-color: rgb(55 65 81); /* gray-700 */
        background: rgb(17 24 39 / 0.5); /* gray-900/50 */
    }
    
    /* Individual cell styling: use padding-top to lock a 1:1 aspect ratio
       so all rows and columns stay aligned (no visual gaps). */
    .ip-cell {
        border-right: 1px solid rgb(229 231 235);
        border-bottom: 1px solid rgb(229 231 235);
        box-sizing: border-box;
        width: 100%;
        /* Height is defined by padding to keep it square */
        height: 0;
        padding-top: 100%;
        position: relative;
        overflow: hidden; /* ensure hover effects or shadows don't push layout */
    }
    
    .dark .ip-cell {
        border-color: rgb(55 65 81);
    }
    
    /* Remove right border on last column */
    .ip-cell:nth-child(20n) {
        border-right: none;
    }
    
    /* Cell content overlay: absolutely fill the padded square cell and center content */
    .ip-cell-link {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: transform 0.12s ease, box-shadow 0.12s ease;
    }
    
    /* Use a subtle lift on hover without affecting surrounding flow */
    .ip-cell-link:hover {
        transform: translateY(-3px);
        z-index: 10;
        box-shadow: 0 6px 14px -6px rgb(0 0 0 / 0.25);
    }
</style>
