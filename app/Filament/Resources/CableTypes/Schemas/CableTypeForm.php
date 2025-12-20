<?php

namespace App\Filament\Resources\CableTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ColorPicker;
use Filament\Schemas\Schema;

class CableTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->label('Name'),

                Select::make('category')
                    ->options([
                        'cat5e' => 'Cat5e',
                        'cat6' => 'Cat6',
                        'cat6a' => 'Cat6a',
                        'fiber_om3' => 'Fiber OM3',
                        'fiber_om4' => 'Fiber OM4',
                        'fiber_os2' => 'Fiber OS2',
                        'dac' => 'Direct Attach (DAC)',
                        'power' => 'Power',
                    ])
                    ->required()
                    ->label('Category'),

                ColorPicker::make('color')
                    ->label('Color')
                    ->default('#000000'),

                Textarea::make('description')
                    ->rows(3)
                    ->label('Description'),
            ]);
    }
}
