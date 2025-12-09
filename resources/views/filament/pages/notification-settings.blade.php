<x-filament::page>
    <div class="space-y-6">
        {{ $this->form }}

        <div class="pt-6">
            <x-filament::card class="p-4">
                <div class="flex items-center justify-end">
                    <x-filament::button wire:click="save">Save settings</x-filament::button>
                </div>
            </x-filament::card>
        </div>
    </div>
</x-filament::page>
