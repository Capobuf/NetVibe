<x-filament-widgets::widget>
    @if (! $record)
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Subnet not available</div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-chart-pie" class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                    <span class="text-sm font-medium">IP Composition</span>
                    <x-filament::badge color="gray" size="sm">{{ $record->cidr ?? $record->subnet }}</x-filament::badge>
                </div>
            </x-slot>

            <div style="height:220px;">
                <div class="fi-wi-chart">
                    <div class="fi-wi-chart-canvas-ctn">
                        <canvas id="subnet-ip-composition-{{ $record->getKey() }}"></canvas>
                    </div>
                </div>
            </div>

            @if (! empty($chartData['labels']))
                <script>
                    (function () {
                        const id = 'subnet-ip-composition-{{ $record->getKey() }}';
                        const ctx = document.getElementById(id)?.getContext('2d');
                        if (! ctx) return;

                        // Build the doughnut chart using Chart.js shipped with Filament
                        new Chart(ctx, {
                            type: 'doughnut',
                            data: @json(array_merge(['labels' => $chartData['labels']], ['datasets' => $chartData['datasets']])),
                            options: @json($chartData['options'] ?? []),
                        });
                    })();
                </script>
            @else
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">No IP addresses present in this subnet (no data).</div>
            @endif

        </x-filament::section>
    @endif
</x-filament-widgets::widget>
