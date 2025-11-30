<div>
    @if (! $record)
        <div class="text-sm text-gray-500">Subnet not available</div>
    @elseif (! $renderGrid)
        <div class="rounded border border-gray-200 p-4 bg-gray-50 text-sm text-gray-700">
            <strong>Visual Grid disabled</strong>
            <div class="mt-2 text-xs text-gray-600">The visual grid is disabled for large subnets or when the subnet cannot be rendered. Use the IP table above to search and create IPs.</div>
        </div>
    @else
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <div class="text-sm font-semibold">Subnet visual map — {{ $record->cidr ?? $record->subnet }}</div>
                <div class="text-xs text-gray-500">Utilization: {{ collect($cells)->where('exists', true)->count() }} / {{ $totalHosts }}</div>
            </div>

            <div class="w-full border rounded p-2 bg-white shadow-sm">
                <div class="grid grid-cols-10 md:grid-cols-20 gap-1">
                    @foreach ($cells as $cell)
                        @php
                            $ip = $cell['ip'] ?? null;
                        @endphp

                        @if ($cell['exists'])
                            @php
                                $color = 'bg-green-500';
                                if (($cell['state'] ?? 0) === 1) { $color = 'bg-red-500'; }
                                if (($cell['state'] ?? 0) === 3) { $color = 'bg-blue-500'; }
                            @endphp
                            @php
                                // Map states/types to visual classes
                                $classes = 'aspect-square rounded-sm text-xs flex items-center justify-center cursor-pointer text-white border';
                                // default: used/active
                                $variant = 'success';
                                if (($cell['type'] ?? '') === 'gateway' || ($cell['type'] ?? '') === 'reserved') {
                                    $variant = 'primary';
                                }
                                if (($cell['state'] ?? 0) === 1) { // offline/alert
                                    $variant = 'danger';
                                }

                                $classes .= match ($variant) {
                                    'primary' => ' bg-primary-500 border-primary-600',
                                    'danger' => ' bg-danger-500 border-danger-600',
                                    default => ' bg-success-500 border-success-600',
                                };
                            @endphp
                            <a href="/admin/ip-addresses/{{ $cell['id'] }}/edit" class="{{ $classes }}" title="{{ $ip }} {{ $cell['hostname'] ? ' - '.$cell['hostname'] : '' }}">
                                {{ \Illuminate\Support\Str::afterLast($ip, '.') ?? $ip }}
                            </a>
                        @else
                            <a href="/admin/ip-addresses/create?subnet_id={{ $record->getKey() }}&ip_addr={{ urlencode($ip) }}" class="aspect-square rounded-sm text-xs flex items-center justify-center cursor-pointer bg-gray-50 border border-gray-200 text-gray-400 hover:bg-gray-100" title="Create {{ $ip }}">
                                <span class="opacity-60">{{ \Illuminate\Support\Str::afterLast($ip, '.') ?? $ip }}</span>
                            </a>
                        @endif

                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
