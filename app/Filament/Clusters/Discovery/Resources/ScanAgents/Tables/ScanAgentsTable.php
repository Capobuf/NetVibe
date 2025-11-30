<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\Tables;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use App\Models\ScanAgent;

class ScanAgentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->sortable(),

                TextColumn::make('active')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                    ->sortable(),

                TextColumn::make('last_seen')
                    ->since()
                    ->label('Last Seen')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('toggleActive')
                    ->label(fn (ScanAgent $record): string => $record->active ? 'Deactivate' : 'Activate')
                    ->action(function (ScanAgent $record): void {
                        $record->update(['active' => ! $record->active]);
                    }),

                EditAction::make()->slideOver(),
            ])
            ->toolbarActions([]);
    }
}
