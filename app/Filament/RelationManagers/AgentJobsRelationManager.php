<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;

class AgentJobsRelationManager extends RelationManager
{
    protected static string $relationship = 'agentJobs';

    protected static ?string $recordTitleAttribute = 'id';

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Job ID')->limit(8),
                TextColumn::make('type')->label('Type'),
                BadgeColumn::make('status')
                    ->colors([
                        'primary' => 'pending',
                        'warning' => 'picked_up',
                        'success' => 'completed',
                        'danger' => 'failed',
                        'secondary' => 'timeout',
                    ])
                    ->label('Status'),
                TextColumn::make('created_at')->dateTime()->label('Created'),
                TextColumn::make('started_at')->dateTime()->label('Started')->toggleable(),
                TextColumn::make('finished_at')->dateTime()->label('Finished')->toggleable(),
            ])
            ->actions([
                ViewAction::make('view_payload')
                    ->label('View')
                    ->modalWidth('7xl')
                    ->modalHeading('Job Details')
                    ->form([
                        TextInput::make('id')->label('Job ID')->disabled(),
                        TextInput::make('type')->label('Type')->disabled(),
                        TextInput::make('status')->label('Status')->disabled(),
                        TextInput::make('created_at')->label('Created')->disabled(),
                        TextInput::make('started_at')->label('Started')->disabled(),
                        TextInput::make('finished_at')->label('Finished')->disabled(),
                        Textarea::make('payload')
                            ->label('Payload')
                            ->formatStateUsing(fn ($record) => json_encode($record->payload ?? new \stdClass(), JSON_PRETTY_PRINT))
                            ->disabled()
                            ->rows(10),
                        Textarea::make('result')
                            ->label('Result')
                            ->formatStateUsing(fn ($record) => json_encode($record->result ?? new \stdClass(), JSON_PRETTY_PRINT))
                            ->disabled()
                            ->rows(10),
                        Textarea::make('output')
                            ->label('Output')
                            ->disabled()
                            ->rows(6),
                    ]),
            ]);
    }
}
