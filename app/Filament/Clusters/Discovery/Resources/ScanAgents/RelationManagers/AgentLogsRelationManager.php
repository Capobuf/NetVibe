<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\RelationManagers;

use App\Models\AgentLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Illuminate\Database\Eloquent\Builder;

class AgentLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'agentLogs';
    protected static ?string $recordTitleAttribute = 'message';
    protected static ?string $title = 'Agent Logs';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),

                BadgeColumn::make('level')
                    ->colors([
                        'secondary' => ['DEBUG', 'INFO'],
                        'warning' => ['WARNING'],
                        'danger' => ['ERROR', 'CRITICAL'],
                    ])
                    ->formatStateUsing(fn ($state) => $state)
                    ->sortable(),

                TextColumn::make('message')
                    ->limit(50)
                    ->searchable()
                    ->sortable(),

            ])
            ->filters([
                SelectFilter::make('level')
                    ->label('Level')
                    ->options([
                        'DEBUG' => 'DEBUG',
                        'INFO' => 'INFO',
                        'WARNING' => 'WARNING',
                        'ERROR' => 'ERROR',
                        'CRITICAL' => 'CRITICAL',
                    ]),
                Filter::make('created_at_range')
                    ->form([
                        Forms\Components\DateTimePicker::make('from')->label('From'),
                        Forms\Components\DateTimePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', $from))
                            ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->where('created_at', '<=', $until));
                    }),
            ])
            ->actions([
                ViewAction::make('view_context')
                    ->label('View')
                    ->modalHeading('Log Details')
                    ->form([
                        Forms\Components\TextInput::make('created_at')
                            ->label('Time')
                            ->disabled(),
                        Forms\Components\TextInput::make('level')
                            ->label('Level')
                            ->disabled(),
                        Forms\Components\Textarea::make('message')
                            ->label('Message')
                            ->rows(3)
                            ->disabled(),
                        Textarea::make('context_json')
                            ->label('Context (JSON)')
                            ->default(fn (AgentLog $record) => json_encode($record->context ?? new \stdClass(), JSON_PRETTY_PRINT))
                            ->rows(10)
                            ->disabled(),
                    ]),
            ])
            ->headerActions([])
            ->emptyStateHeading('No logs yet')
            ->emptyStateDescription('This agent has not sent any logs.');
    }
}
