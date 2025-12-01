<div class="space-y-3">
    @if (count($results) === 0)
        <div class="text-yellow-400">No checks executed yet.</div>
    @else
        <ul class="space-y-2">
            @foreach ($results as $r)
                @php
                    $status = $r['status'] ?? 'unknown';
                    $color = $status === 'ok' ? 'text-green-400' : ($status === 'warning' ? 'text-yellow-400' : 'text-red-400');
                @endphp
                <li class="flex items-start gap-3">
                    <div class="flex-shrink-0">
                        <span class="inline-block w-3 h-3 rounded-full {{ $color }} mt-1"></span>
                    </div>
                    <div>
                        <div class="font-semibold">{{ $r['name'] ?? $r['id'] }}</div>
                        <div class="text-slate-400 text-sm">{{ $r['message'] ?? '' }} @if(!empty($r['details'])) — <span class="text-xs text-slate-500">{{ $r['details'] }}</span>@endif</div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
