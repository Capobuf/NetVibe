<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\AgentJobs\Tables;

use App\Models\AgentJob;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AgentJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Job ID')
                    ->limit(8)
                    ->copyable()
                    ->copyMessage('Job ID copied'),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge(),

                TextColumn::make('scope')
                    ->label('Scope')
                    ->badge()
                    ->placeholder('device'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending', 'queued' => 'warning',
                        'picked_up', 'running' => 'info',
                        'completed', 'success' => 'success',
                        'partial', 'partial_success' => 'gray',
                        'timeout', 'expired' => 'danger',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('device.hostname')
                    ->label('Device')
                    ->searchable(),

                TextColumn::make('scanAgent.name')
                    ->label('Agent')
                    ->sortable(),

                TextColumn::make('tenant.name')
                    ->label('Customer')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Queued at')
                    ->since()
                    ->sortable(),

                TextColumn::make('started_at')
                    ->label('Started')
                    ->since()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('finished_at')
                    ->label('Finished')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(fn (): array => AgentJob::query()
                        ->select('type')
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->toArray()),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Queued',
                        'picked_up' => 'Running',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'timeout' => 'Timeout',
                        'partial_success' => 'Partial',
                        'expired' => 'Expired',
                    ]),

                SelectFilter::make('tenant_id')
                    ->label('Customer')
                    ->relationship('tenant', 'name'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View details')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Job details')
                    ->modalWidth('7xl')
                    ->slideOver()
                    ->form(fn (AgentJob $record): array => [
                        \Filament\Forms\Components\TextInput::make('id')
                            ->label('Job ID')
                            ->default($record->id)
                            ->disabled(),
                        \Filament\Forms\Components\TextInput::make('type')
                            ->label('Type')
                            ->default($record->type)
                            ->disabled(),
                        \Filament\Forms\Components\TextInput::make('status')
                            ->label('Status')
                            ->default($record->status)
                            ->disabled(),
                        \Filament\Forms\Components\TextInput::make('device')
                            ->label('Device')
                            ->default($record->device?->hostname)
                            ->disabled(),
                        \Filament\Forms\Components\TextInput::make('agent')
                            ->label('Agent')
                            ->default($record->scanAgent?->name)
                            ->disabled(),
                        \Filament\Forms\Components\Textarea::make('payload')
                            ->label('Payload')
                            ->default(json_encode($record->payload ?? new \stdClass(), JSON_PRETTY_PRINT))
                            ->rows(8)
                            ->disabled(),
                        \Filament\Forms\Components\Textarea::make('result')
                            ->label('Result')
                            ->default(json_encode($record->result ?? new \stdClass(), JSON_PRETTY_PRINT))
                            ->rows(8)
                            ->disabled(),
                        \Filament\Forms\Components\Textarea::make('result_metadata')
                            ->label('Result metadata')
                            ->default(json_encode($record->result_metadata ?? new \stdClass(), JSON_PRETTY_PRINT))
                            ->rows(4)
                            ->disabled(),
                        \Filament\Forms\Components\Textarea::make('error_message')
                            ->label('Error message')
                            ->default($record->error_message)
                            ->rows(3)
                            ->disabled(),
                    ]),
            ])
            ->toolbarActions([]);
    }
}

