<div class="bg-gray-900 border border-gray-700 rounded-lg p-4">
    @php
        $state = $getState();
        $units = $state['units'] ?? [];
        $rack = $state['rack'] ?? null;

        // helper: semantic color -> tailwind classes
        $colorMap = [
            'danger' => ['bg' => 'bg-red-100', 'text' => 'text-red-700', 'ring' => 'ring-red-400'],
            'warning' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'ring' => 'ring-amber-400'],
            'info' => ['bg' => 'bg-sky-100', 'text' => 'text-sky-700', 'ring' => 'ring-sky-400'],
            'success' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'ring' => 'ring-emerald-400'],
            'gray' => ['bg' => 'bg-gray-700', 'text' => 'text-gray-200', 'ring' => 'ring-gray-400'],
        ];
    @endphp
    <h3 class="text-white text-lg font-semibold mb-4">Rack Visualization (42U)</h3>
    @php
        // graphical rendering constants
        // increase per-unit height so placeholders and device blocks are visually clear
        $unitHeight = 36; // px per U
        $totalUnits = 42;
        $totalHeight = $unitHeight * $totalUnits; // px
    @endphp

    <div class="flex gap-4">
        {{-- Left ruler removed to avoid duplication of unit labels; UI should show graphical rack only --}}

        {{-- The rack drawing area: block-based grid using flex-col-reverse so U1 is bottom --}}
        <div class="relative w-72 mx-auto bg-gray-800 border border-gray-700 rounded p-3 overflow-hidden" style="min-height: {{ $totalHeight }}px;">

            {{-- units container: render blocks bottom-to-top using flex-col-reverse (1U increments) --}}
            <div class="w-full h-full flex flex-col-reverse gap-1 overflow-auto" style="height: {{ $totalHeight }}px;">
                @php $u = 1; @endphp
                @while($u <= $totalUnits)
                    @php $data = $units[$u] ?? ['type' => 'empty']; @endphp

                    @if($data['type'] === 'spanned')
                        {{-- skip spanned units (part of a device rendered at the starting unit) --}}
                        @php $u++; continue; @endphp
                    @endif

                    @if($data['type'] === 'device')
                        @php
                            $device = $data['device'];
                            $span = (int) ($data['span'] ?? 1);
                            $heightPx = max(32, $span * $unitHeight);
                            $colorKey = $device->deviceModel->type?->getColor() ?? 'gray';
                            $classes = $colorMap[$colorKey] ?? $colorMap['gray'];
                            $editUrl = \App\Filament\Clusters\Racks\Resources\Devices\DeviceResource::getUrl('edit', ['record' => $device->id]);
                        @endphp

                        <a href="{{ $editUrl }}" title="Edit {{ $device->hostname }}" aria-label="Edit {{ $device->hostname }}"
                           class="block w-full flex items-center justify-center px-2 py-1 transition-shadow hover:shadow-md"
                           style="height: {{ $heightPx }}px; background-color: transparent;">
                            <div class="w-full h-full flex flex-col items-center justify-center text-xs font-medium rounded {{ $classes['bg'] }} {{ $classes['text'] }} border border-gray-600">
                                <div class="font-semibold truncate px-1">{{ $device->hostname }}</div>
                                <div class="text-[11px] opacity-80 truncate px-1">{{ $device->deviceModel->name ?? 'Unknown' }}</div>
                            </div>
                        </a>

                        @php $u += $span; continue; @endphp
                    @else
                        @php
                            $createUrl = \App\Filament\Clusters\Racks\Resources\Devices\DeviceResource::getUrl('create') . '?rack_id=' . ($rack->id ?? '') . '&rack_position=' . $u;
                        @endphp

                        <a href="{{ $createUrl }}" title="Create device at U{{ $u }}" aria-label="Create device at U{{ $u }}"
                           class="block w-full flex items-center justify-between px-3 text-sm text-gray-200 border border-gray-600 bg-gray-700/40 hover:bg-gray-700 transition-colors"
                           style="height: {{ $unitHeight }}px;">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-3 bg-gray-400/30 rounded"></div>
                                <div class="opacity-90 font-semibold">U{{ $u }}</div>
                            </div>
                            <div class="text-sm opacity-80">Create</div>
                        </a>

                        @php $u++; continue; @endphp
                    @endif
                @endwhile
            </div>

            @php
                // friendly message if everything somehow still empty
                $hasAny = collect($units)->contains(function ($data) {
                    return $data['type'] !== 'spanned';
                });
            @endphp

            @unless($hasAny)
                <div class="absolute inset-0 flex items-center justify-center text-sm text-gray-400 select-none pointer-events-none">
                    This rack is empty — use the slots to create devices.
                </div>
            @endunless

        </div>
    </div>
</div>
