<div>
    @if($show)
        <div class="fixed inset-0 z-50 flex items-center justify-center">
            <div class="fixed inset-0 bg-black/50" wire:click="resetModal"></div>

            <div class="relative z-50 w-full max-w-2xl">
                <div class="bg-white dark:bg-gray-900 rounded-lg shadow-lg p-6">
                    <h3 class="text-lg font-semibold">Connect port</h3>

                    <div class="mt-4 grid grid-cols-1 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Source port</label>
                            <div class="mt-1 text-sm text-gray-700">{{ optional(\App\Models\Port::find($portId))->name ?? '—' }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Target device</label>
                            <select wire:model="target_device_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">Choose device</option>
                                @foreach($devices as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Target port</label>
                            <select wire:model="target_port_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">Choose port</option>
                                @foreach($targetPorts as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Cable type</label>
                            <select wire:model="cable_type_id" class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">(optional)</option>
                                @foreach($cableTypes as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex justify-end gap-2 mt-4">
                            <button wire:click="resetModal" type="button" class="inline-flex items-center px-3 py-2 bg-gray-100 rounded-md">Cancel</button>
                            <button wire:click.prevent="connect" type="button" class="inline-flex items-center px-3 py-2 bg-primary-600 text-white rounded-md">Connect</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
