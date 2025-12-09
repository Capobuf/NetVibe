<x-filament::page>
    @php($health = $this->getHealthData())

    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold">System Health</h2>
        <x-filament::button wire:click="runHealthCheck">
            Run Health Check Now
        </x-filament::button>
    </div>

    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
        <x-filament::card>
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-gray-500">Scheduler</div>
                    <div class="mt-1 text-lg font-semibold">
                        {{ $health['scheduler']['label'] }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ $health['scheduler']['message'] }}
                    </div>
                    @if($health['scheduler']['last'])
                        <div class="mt-1 text-xs text-gray-400">
                            Last run: {{ $health['scheduler']['last']->diffForHumans() }}
                        </div>
                    @endif
                </div>
                <span @class([
                    'px-2 py-1 text-xs font-semibold rounded-full',
                    'bg-green-100 text-green-800' => $health['scheduler']['status'] === 'OK',
                    'bg-yellow-100 text-yellow-800' => $health['scheduler']['status'] === 'WARNING',
                    'bg-red-100 text-red-800' => $health['scheduler']['status'] === 'CRITICAL',
                    'bg-gray-100 text-gray-800' => $health['scheduler']['status'] === 'UNKNOWN',
                ])>
                    {{ $health['scheduler']['status'] }}
                </span>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-gray-500">Worker</div>
                    <div class="mt-1 text-lg font-semibold">
                        {{ $health['worker']['label'] }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ $health['worker']['message'] }}
                    </div>
                    @if($health['worker']['last'])
                        <div class="mt-1 text-xs text-gray-400">
                            Last heartbeat: {{ $health['worker']['last']->diffForHumans() }}
                        </div>
                    @endif
                </div>
                <span @class([
                    'px-2 py-1 text-xs font-semibold rounded-full',
                    'bg-green-100 text-green-800' => $health['worker']['status'] === 'OK',
                    'bg-yellow-100 text-yellow-800' => $health['worker']['status'] === 'WARNING',
                    'bg-red-100 text-red-800' => $health['worker']['status'] === 'CRITICAL',
                    'bg-gray-100 text-gray-800' => $health['worker']['status'] === 'UNKNOWN',
                ])>
                    {{ $health['worker']['status'] }}
                </span>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-gray-500">Database</div>
                    <div class="mt-1 text-lg font-semibold">
                        {{ $health['db']['status'] }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ $health['db']['message'] }}
                    </div>
                </div>
                <span @class([
                    'px-2 py-1 text-xs font-semibold rounded-full',
                    'bg-green-100 text-green-800' => $health['db']['status'] === 'UP',
                    'bg-red-100 text-red-800' => $health['db']['status'] === 'DOWN',
                    'bg-gray-100 text-gray-800' => $health['db']['status'] === 'N/A',
                ])>
                    {{ $health['db']['status'] }}
                </span>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-gray-500">Redis</div>
                    <div class="mt-1 text-lg font-semibold">
                        {{ $health['redis']['status'] }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ $health['redis']['message'] }}
                    </div>
                </div>
                <span @class([
                    'px-2 py-1 text-xs font-semibold rounded-full',
                    'bg-green-100 text-green-800' => $health['redis']['status'] === 'UP',
                    'bg-red-100 text-red-800' => $health['redis']['status'] === 'DOWN',
                    'bg-gray-100 text-gray-800' => $health['redis']['status'] === 'N/A',
                ])>
                    {{ $health['redis']['status'] }}
                </span>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-gray-500">Storage</div>
                    <div class="mt-1 text-lg font-semibold">
                        {{ $health['storage']['status'] }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ $health['storage']['message'] }}
                    </div>
                </div>
                <span @class([
                    'px-2 py-1 text-xs font-semibold rounded-full',
                    'bg-green-100 text-green-800' => $health['storage']['status'] === 'UP',
                    'bg-red-100 text-red-800' => $health['storage']['status'] === 'DOWN',
                    'bg-gray-100 text-gray-800' => $health['storage']['status'] === 'N/A',
                ])>
                    {{ $health['storage']['status'] }}
                </span>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium text-gray-500">Agents</div>
                    <div class="mt-1 text-lg font-semibold">
                        {{ $health['agents']['with_last_seen'] }} / {{ $health['agents']['total'] }} with last_seen
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        @if($health['agents']['last_seen_max'])
                            Last seen max: {{ $health['agents']['last_seen_max']->diffForHumans() }}
                        @else
                            No agent heartbeat recorded yet.
                        @endif
                    </div>
                    <div class="mt-1 text-xs text-gray-400">
                        Queue backlog metric e offline/online precisi verranno aggiunti in una fase successiva secondo PDL.
                    </div>
                </div>
            </div>
        </x-filament::card>
    </div>

    @if($health['last_health_check_at'])
        <div class="mt-6 text-xs text-gray-400">
            Last manual health check: {{ $health['last_health_check_at']->diffForHumans() }}
        </div>
    @endif
</x-filament::page>

