@props(['options' => []])

<div class="fi-sidebar-nav-groups -mx-2 mb-4">
    <ul class="fi-sidebar-group-items flex flex-col gap-y-1">
        <li class="fi-sidebar-item px-2">
            <x-filament::dropdown placement="bottom-start" class="w-full">

                <x-slot name="trigger">
                    <button
                        type="button"
                        class="fi-sidebar-item-btn group w-full items-center gap-x-3 px-2 py-2 text-start text-sm font-medium text-gray-700 outline-none transition duration-75 hover:bg-gray-100 focus-visible:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
                    >
                        {{-- Icona (Manteniamo lo stile nativo) --}}
                        <x-filament::icon
                            :icon="$tenantId ? 'heroicon-m-building-office' : 'heroicon-m-globe-alt'"
                            class="fi-sidebar-item-icon h-6 w-6 shrink-0 text-gray-400 transition duration-75 group-hover:text-gray-500 group-focus-visible:text-gray-500 dark:text-gray-500 dark:group-hover:text-gray-400 dark:group-focus-visible:text-gray-400"
                        />

                        {{-- Testo --}}
                        <span class="fi-sidebar-item-label flex-1 truncate font-medium text-gray-700 dark:text-gray-200">
                            {{ $options[$tenantId] ?? __('All Customers') }}
                        </span>

                        {{-- Chevron --}}
                        <x-filament::icon
                            icon="heroicon-m-chevron-down"
                            class="fi-sidebar-item-grouper-toggle-icon h-4 w-4 shrink-0 text-gray-400 transition duration-75 group-hover:text-gray-500 dark:text-gray-500 dark:group-hover:text-gray-400"
                        />
                    </button>
                </x-slot>

                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item wire:click="activateTenant(null)" icon="heroicon-m-globe-alt" :color="$tenantId === null ? 'primary' : 'gray'">
                        {{ __('All Customers') }}
                    </x-filament::dropdown.list.item>

                    @foreach($options as $id => $name)
                        <x-filament::dropdown.list.item wire:click="activateTenant('{{ $id }}')" icon="heroicon-m-building-office" :color="$tenantId == $id ? 'primary' : 'gray'">
                            {{ $name }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>

            </x-filament::dropdown>
        </li>
    </ul>
</div>
