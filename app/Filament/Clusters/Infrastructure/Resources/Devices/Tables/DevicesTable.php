<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\Devices\Tables;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\CreateAction;

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
        ]);
    }
}
