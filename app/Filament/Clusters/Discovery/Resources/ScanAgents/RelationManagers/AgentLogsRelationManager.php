<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\RelationManagers;

use App\Models\AgentLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Actions\ViewAction;

class AgentLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'agentLogs';
    protected static ?string $recordTitleAttribute = 'message';
    protected static ?string $title = 'Agent Logs';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                BadgeColumn::make('level')
                    ->colors([
                        'secondary' => 'INFO',
                        'warning' => 'WARNING',
                        'danger' => fn ($state) => in_array($state, ['ERROR', 'CRITICAL']),
                    ])
                    ->formatStateUsing(fn ($state) => $state)
                    ->sortable(),

                TextColumn::make('message')
                    ->limit(50)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                ViewAction::make()
                    ->modalHeading('Log Details')
                    ->modalContent(fn (AgentLog $record) => view('filament.modals.agent-log-details', ['record' => $record])),
            ])
            ->headerActions([])
            ->emptyStateHeading('No logs yet')
            ->emptyStateDescription('This agent has not sent any logs.');
    }
}
