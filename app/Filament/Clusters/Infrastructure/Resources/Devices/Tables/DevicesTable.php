<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\Devices\Tables;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\CreateAction;
use App\Enums\LifecycleStatus;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make()->slideOver(),
            ])
            ->columns([
            TextColumn::make('hostname')->searchable()->weight('bold'),
            TextColumn::make('ip_addr')->label('IP'),
            TextColumn::make('deviceModel.model')->label('Model')->toggleable(),
            TextColumn::make('lifecycle_status')
                ->label('Lifecycle')
                ->badge()
                ->formatStateUsing(fn ($state) => ($state instanceof LifecycleStatus ? $state : LifecycleStatus::from((int) $state))->label())
                ->color(fn ($state) => match (($state instanceof LifecycleStatus ? $state : LifecycleStatus::from((int) $state))) {
                    LifecycleStatus::Active => 'success',
                    LifecycleStatus::Stale => 'warning',
                    LifecycleStatus::Retired => 'danger',
                })
                ->toggleable(),
        ]);
    }
}
