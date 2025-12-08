@props(['options' => []])

<x-filament::dropdown placement="bottom-start">
    <x-slot name="trigger">
        <x-filament::button
            color="gray"
            icon="{{ $tenantId ? 'heroicon-m-building-office' : 'heroicon-m-globe-alt' }}"
            icon-position="before"
            tag="button"
        >
            {{ $options[$tenantId] ?? __('All Customers') }}
        </x-filament::button>
    </x-slot>

    <x-filament::dropdown.list>
        <x-filament::dropdown.list.item
            wire:click="activateTenant(null)"
            icon="heroicon-m-globe-alt"
            :color="$tenantId === null ? 'primary' : 'gray'"
        >
            {{ __('All Customers') }}
        </x-filament::dropdown.list.item>

        @foreach($options as $id => $name)
            <x-filament::dropdown.list.item
                wire:click="activateTenant('{{ $id }}')"
                icon="heroicon-m-building-office"
                :color="$tenantId == $id ? 'primary' : 'gray'"
            >
                {{ $name }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
