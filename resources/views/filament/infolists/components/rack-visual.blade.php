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
    <div class="space-y-1">
        @foreach($units as $unit => $data)
            @if($data['type'] === 'device')
                @php
                    $device = $data['device'];
                    $span = (int) ($data['span'] ?? 1);
                    $colorKey = $device->deviceModel->type?->getColor() ?? 'gray';
                    $classes = $colorMap[$colorKey] ?? $colorMap['gray'];
                    // Inline height: approx 1rem per U
                    $heightPx = max(32, $span * 24);
                    $editUrl = \App\Filament\Clusters\Racks\Resources\Devices\DeviceResource::getUrl('edit', ['record' => $device->id]);
                @endphp

                <a href="{{ $editUrl }}" class="block rounded p-2 cursor-pointer transition-shadow shadow-sm hover:shadow-md" style="background-color:transparent;">
                    <div class="rounded border px-2 py-1 flex flex-col justify-center items-center text-xs font-medium" style="height: {{ $heightPx }}px; background-color:transparent;">
                        <div class="{{ $classes['bg'] }} {{ $classes['text'] }} rounded w-full h-full flex flex-col items-center justify-center">
                            <div class="font-semibold">{{ $device->hostname }}</div>
                            <div class="text-xs opacity-75">{{ $device->deviceModel->name ?? 'Unknown' }}</div>
                        </div>
                    </div>
                </a>
            @elseif($data['type'] === 'spanned')
                {{-- part of a multi-U device already rendered at its starting unit; skip visual block --}}
                @continue
            @else
                @php
                    $createUrl = \App\Filament\Clusters\Racks\Resources\Devices\DeviceResource::getUrl('create') . '?rack_id=' . ($rack->id ?? '') . '&rack_position=' . $unit;
                @endphp

                <a href="{{ $createUrl }}" class="block border-2 border-dashed border-gray-600 rounded p-2 hover:bg-gray-600 transition-colors h-8 flex items-center justify-center text-xs font-medium text-gray-400">
                    U{{ $unit }}
                </a>
            @endif
        @endforeach
    </div>
</div>
