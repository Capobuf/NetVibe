<?php

namespace App\Filament\Clusters\Racks\Resources\Racks\Widgets;

use App\Models\Device;
use App\Models\Rack;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class RackVisualizerWidget extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    public ?Rack $record = null;

    protected function getTableQuery(): Builder
    {
        return Device::query()
            ->with('deviceModel')
            ->where('rack_id', $this->record?->id ?? 0)
            ->orderBy('rack_position');
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('hostname')
                ->label('Device')
                ->searchable()
                ->weight('bold'),
            Tables\Columns\TextColumn::make('deviceModel.name')
                ->label('Model')
                ->toggleable(),
            Tables\Columns\TextColumn::make('rack_position')
                ->label('U start')
                ->sortable(),
            Tables\Columns\TextColumn::make('rack_size')
                ->label('U size')
                ->sortable()
                ->placeholder(fn ($record) => $record->deviceModel?->u_size ?? null),
            Tables\Columns\TextColumn::make('ip_addr')
                ->label('Mgmt IP')
                ->toggleable(),
        ];
    }
}
