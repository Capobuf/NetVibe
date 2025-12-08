<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\CredentialProfiles\Tables;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class CredentialProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->weight('bold'),
            TextColumn::make('type')->label('Auth Type')->sortable(),
            TextColumn::make('username')->label('Username')->toggleable(),
        ]);
    }
}
