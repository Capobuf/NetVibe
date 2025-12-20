<x-filament-widgets::widget>
    @if (! $record)
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Rack not available</div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-inbox" class="h-5 w-5 text-gray-500" />
                    <span class="text-sm font-medium">Rack Visual</span>
                    <x-filament::badge color="gray" size="sm">{{ $height }}U</x-filament::badge>
                </div>
            </x-slot>

            @php
                // Simplified SVG layout — keep a minimal, stable renderer so
                // the production widget doesn't contain experimental port math.
                $canvasWidth = 900;
                $uPixelHeight = 60; // 1U = 60px
                $totalUnits = (int) ($height ?? 42);
                $canvasHeight = max(240, $totalUnits * $uPixelHeight);

                $leftLabelW = 56;
                $rightLabelW = 56;
                $chassisX = $leftLabelW + 8;
                $chassisW = $canvasWidth - ($leftLabelW + $rightLabelW + 24);

                $dividerColor = '#374151';
                $labelColor = '#9ca3af';
            @endphp

            <div style="overflow:auto">
                <svg width="100%" height="auto" viewBox="0 0 {{ $canvasWidth }} {{ $canvasHeight }}" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">

                    {{-- Background --}}
                    <rect x="0" y="0" width="{{ $canvasWidth }}" height="{{ $canvasHeight }}" fill="#1a202c" rx="8" />

                    {{-- Unit divisions and labels --}}
                    @for($u = $totalUnits; $u >= 1; $u--)
                        @php
                            // vertical position for the top of this unit
                            $yPos = ($totalUnits - $u) * $uPixelHeight;
                            $lineY = $yPos + $uPixelHeight; // bottom of the unit for divider
                        @endphp

                        {{-- Divider line (bottom of the unit) --}}
                        <line x1="{{ $leftLabelW }}" y1="{{ $lineY }}" x2="{{ $canvasWidth - $rightLabelW }}" y2="{{ $lineY }}" stroke="{{ $dividerColor }}" stroke-width="1" />

                        {{-- Left label --}}
                        <text x="{{ ($leftLabelW / 2) }}" y="{{ $yPos + ($uPixelHeight / 2) + 4 }}" font-size="12" fill="{{ $labelColor }}" text-anchor="middle" alignment-baseline="middle" font-family="monospace">U{{ $u }}</text>

                        {{-- Right label --}}
                        <text x="{{ $canvasWidth - ($rightLabelW / 2) }}" y="{{ $yPos + ($uPixelHeight / 2) + 4 }}" font-size="12" fill="{{ $labelColor }}" text-anchor="middle" alignment-baseline="middle" font-family="monospace">U{{ $u }}</text>
                    @endfor

                    {{-- Draw devices (use devicesMap keyed by starting unit) --}}
                    @foreach($devicesMap as $startUnit => $device)
                        @php
                            $startUnit = (int) $startUnit;
                            // Simplified device size logic — prefer u_size fields and fallback to 1
                            $dHeightUnits = (int) ($device->u_size ?? $device->height ?? $device->rack_size ?? $device->deviceModel?->u_size ?? 1);
                            $dPixelHeight = ($dHeightUnits * $uPixelHeight) - 4;

                            $chX = $chassisX;
                            $chY = ($totalUnits - $startUnit) * $uPixelHeight + 4;
                            $chW = $chassisW;
                        @endphp

                        {{-- Device chassis (simple placeholder; ports rendering intentionally removed) --}}
                        <g class="device" data-start-unit="{{ $startUnit }}">
                            <rect x="{{ $chX }}" y="{{ $chY }}" width="{{ $chW }}" height="{{ $dPixelHeight }}" rx="4" ry="4" fill="#0b1220" stroke="#374151" />
                            <text x="{{ $chX + 8 }}" y="{{ $chY + 18 }}" font-size="12" fill="#cbd5e1" font-family="monospace">{{ $device->hostname ?? $device->name ?? 'device' }}</text>
                        </g>
                    @endforeach

                </svg>
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
