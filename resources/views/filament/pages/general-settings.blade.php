<x-filament::page>
    <div class="space-y-6">
        {{ $this->form }}

        <div class="pt-6">
            {{-- place the save action in its own card so it's visually separated from the form box --}}
            <x-filament::card class="p-4">
                <div class="flex items-center justify-end">
                    <x-filament::button wire:click="save">Save settings</x-filament::button>
                </div>
            </x-filament::card>
        </div>
    </div>
</x-filament::page>
