<?php

namespace App\Filament\Resources\Links\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ColorPicker;
use App\Models\Port;
use App\Models\CableType;

class LinkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('port_a_id')
                    ->label('Port A')
                    ->default(fn () => request()->query('port_a_id'))
                    ->rules(['required', 'integer'])
                    ->options(function () {
                        return Port::with('device')
                            ->orderBy('device_id')
                            ->get()
                            ->mapWithKeys(fn($p) => [$p->id => optional($p->device)->hostname . ': ' . $p->name])
                            ->toArray();
                    })
                    ->searchable()
                    ->required(),

                Select::make('port_b_id')
                    ->label('Port B')
                    ->rules(['required', 'integer', 'different:port_a_id'])
                    ->options(function ($get) {
                        $portA = $get('port_a_id');
                        $q = Port::with('device')->where('id', '!=', $portA);
                        return $q->get()->mapWithKeys(fn($p) => [$p->id => optional($p->device)->hostname . ': ' . $p->name])->toArray();
                    })
                    ->searchable()
                    ->required()
                    ->disabled(fn($get) => ! $get('port_a_id')),

                Select::make('cable_type_id')
                    ->label('Cable Type')
                    ->options(fn() => CableType::orderBy('name')->pluck('name', 'id')->toArray())
                    ->preload()
                    ->required(),

                TextInput::make('length')
                    ->label('Length (m)')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0),

                ColorPicker::make('color')->label('Color'),

                Textarea::make('notes')->rows(3),
            ]);
    }
}
