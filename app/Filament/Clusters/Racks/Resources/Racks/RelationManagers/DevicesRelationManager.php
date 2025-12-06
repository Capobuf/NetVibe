<?php

namespace App\Filament\Clusters\Racks\Resources\Racks\RelationManagers;

use App\Models\Device;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\CreateAction;
use Filament\Actions\AssociateAction;
use Illuminate\Database\Eloquent\Builder;

class DevicesRelationManager extends RelationManager
{
    protected static string $relationship = 'devices';

    protected static ?string $recordTitleAttribute = 'hostname';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hostname')->label('Hostname')->searchable()->sortable(),
                TextColumn::make('deviceModel.name')->label('Model')->searchable()->sortable(),
                TextColumn::make('rack_position')->label('U')->sortable(),
            ])
            ->headerActions([
                // allow quick creation as before
                CreateAction::make()->slideOver()
                    ->relationship(fn () => $this->getRelationship())
                    ->label('Create device'),

                // allow associating existing (orphan) devices that belong to the same customer
                AssociateAction::make()
                    ->label('Associate existing device')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['hostname', 'serial_number'])
                    ->recordSelectOptionsQuery(function (Builder $query) {
                        $owner = $this->getOwnerRecord();

                        // Only show devices for the same customer
                        if (! empty($owner->customer_id)) {
                            $query->where('customer_id', $owner->customer_id);
                        }

                        // Only show orphan devices (no rack assigned)
                        $query->whereNull('rack_id')->orderBy('hostname');

                        return $query;
                    }),
            ])
            ->searchable();
    }
}
