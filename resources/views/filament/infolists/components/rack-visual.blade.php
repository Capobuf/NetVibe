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
        $unitHeight = 24; // px per U
        $totalUnits = 42;
        $totalHeight = $unitHeight * $totalUnits; // px
    @endphp

    <div class="flex gap-4">
        {{-- Optional left column shows small ruler/labels (hidden on very small screens) --}}
        <div class="hidden sm:flex flex-col-reverse w-12 text-xs text-gray-400 select-none" aria-hidden="true">
            @for ($u = $totalUnits; $u >= 1; $u--)
                <div style="height: {{ $unitHeight }}px; line-height: {{ $unitHeight }}px;" class="flex items-center justify-center">U{{ $u }}</div>
            @endfor
        </div>

        {{-- The rack drawing area: absolute positioning with bottom-origin so U1 is bottom --}}
        <div class="relative flex-1 bg-gray-800 border border-gray-700 rounded p-2 overflow-hidden" style="height: {{ $totalHeight }}px;">

            {{-- background grid lines (visual guide) --}}
            <div class="absolute inset-0 pointer-events-none">
                @for ($u = 0; $u <= $totalUnits; $u++)
                    @php $y = $u * $unitHeight; @endphp
                    <div style="position:absolute; left:0; right:0; bottom: {{ $y }}px; border-bottom: 1px solid rgba(255,255,255,0.04);"></div>
                @endfor
            </div>

            {{-- Empty slot hotspots (preserve Quick Create) --}}
            @foreach($units as $unit => $data)
                @if($data['type'] === 'empty')
                    @php
                        $createUrl = \App\Filament\Clusters\Racks\Resources\Devices\DeviceResource::getUrl('create') . '?rack_id=' . ($rack->id ?? '') . '&rack_position=' . $unit;
                        $bottom = ($unit - 1) * $unitHeight;
                    @endphp

                    <a href="{{ $createUrl }}"
                       class="absolute left-2 right-2 border-2 border-dashed border-gray-600 rounded hover:bg-gray-700 transition-colors flex items-center justify-center text-xs font-medium text-gray-400"
                       style="bottom: {{ $bottom }}px; height: {{ $unitHeight }}px;">
                        <span>U{{ $unit }}</span>
                    </a>
                @endif
            @endforeach

            {{-- Device blocks (render on top) --}}
            @foreach($units as $unit => $data)
                @if($data['type'] === 'device')
                    @php
                        $device = $data['device'];
                        $span = (int) ($data['span'] ?? 1);
                        $colorKey = $device->deviceModel->type?->getColor() ?? 'gray';
                        $classes = $colorMap[$colorKey] ?? $colorMap['gray'];
                        $heightPx = max(32, $span * $unitHeight);
                        // position bottom at the UNIT's bottom coordinate (U1 bottom = 0)
                        $bottom = ($unit - 1) * $unitHeight;
                        $editUrl = \App\Filament\Clusters\Racks\Resources\Devices\DeviceResource::getUrl('edit', ['record' => $device->id]);
                    @endphp

                    <a href="{{ $editUrl }}"
                       class="absolute left-2 right-2 rounded cursor-pointer transition-shadow shadow-sm hover:shadow-md flex items-center justify-center z-10"
                       style="bottom: {{ $bottom }}px; height: {{ $heightPx }}px;">
                        <div class="w-full h-full flex flex-col items-center justify-center text-xs font-medium rounded {{ $classes['bg'] }} {{ $classes['text'] }}">
                            <div class="font-semibold truncate px-1">{{ $device->hostname }}</div>
                            <div class="text-[11px] opacity-80 truncate px-1">{{ $device->deviceModel->name ?? 'Unknown' }}</div>
                        </div>
                    </a>
                @endif
            @endforeach

        </div>
    </div>
</div>
