<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\IpAddresses\Schemas;

use App\Enums\IpAddressType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;

class IpAddressSchema
{
    /**
     * Return a reusable array of Filament form components for IpAddress create/edit forms.
     *
     * @return array<int, object>
     */
    public static function schema(): array
    {
        return [
            TextInput::make('ip_addr')
                ->required(),

            TextInput::make('hostname')
                ->default(null),

            TextInput::make('mac_address')
                ->default(null),

            Select::make('type')
                ->options(IpAddressType::class)
                ->required()
                ->default(IpAddressType::Host->value),

            TextInput::make('state')
                ->numeric()
                ->default(2),

            Textarea::make('description')
                ->default(null),
        ];
    }
}
