<?php

namespace App\Filament\Clusters\Racks\Resources\Devices\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Table;
use App\Filament\Exports\DeviceExporter;
use App\Enums\LifecycleStatus;
use Filament\Tables\Filters\SelectFilter;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->headerActions([
                ExportAction::make()
                    ->exporter(DeviceExporter::class),
            ])
            ->columns([
                TextColumn::make('hostname')->searchable()->weight('bold'),
                TextColumn::make('management_ip')->label('Mgmt IP')->searchable()->toggleable(),
                TextColumn::make('ip_addr')->label('IP')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deviceModel.name')->label('Model')->toggleable(),
                TextColumn::make('auth_status')->label('Auth')->badge()->color(fn ($state) => match($state) {
                    'valid' => 'success',
                    'invalid' => 'danger',
                    'unreachable' => 'warning',
                    default => 'secondary',
                }),
                BadgeColumn::make('health_status')
                    ->label('Health')
                    ->getStateUsing(function ($record) {
                        $last = $record->last_seen_overall;
                        if (! $last) {
                            return 'Unknown';
                        }

                        $diffMinutes = now()->diffInMinutes($last);
                        if ($diffMinutes <= 5) {
                            return 'Healthy';
                        }
                        if ($diffMinutes <= 60) {
                            return 'Partial';
                        }

                        return 'Stale';
                    })
                    ->colors([
                        'success' => 'Healthy',
                        'warning' => 'Partial',
                        'danger' => 'Stale',
                        'gray' => 'Unknown',
                    ]),
                TextColumn::make('lifecycle_status')
                    ->label('Lifecycle')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ($state instanceof LifecycleStatus ? $state : LifecycleStatus::from((int) $state))->label())
                    ->color(fn ($state) => match (($state instanceof LifecycleStatus ? $state : LifecycleStatus::from((int) $state))) {
                        LifecycleStatus::Active => 'success',
                        LifecycleStatus::Stale => 'warning',
                        LifecycleStatus::Retired => 'danger',
                    })
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_seen_overall')
                    ->label('Last Seen')
                    ->dateTime()
                    ->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('lifecycle_status')
                    ->label('Lifecycle')
                    ->options([
                        (string) LifecycleStatus::Active->value => LifecycleStatus::Active->label(),
                        (string) LifecycleStatus::Stale->value => LifecycleStatus::Stale->label(),
                        (string) LifecycleStatus::Retired->value => LifecycleStatus::Retired->label(),
                    ]),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                Action::make('test_connection')
                    ->label('Test Connection')
                    ->icon('heroicon-o-wifi')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        app(\App\Actions\Agent\DispatchDeviceJobAction::class)->execute($record, 'check_connectivity', $record->scan_agent_id, auth()->id());
                        \Filament\Notifications\Notification::make()
                            ->title('Connectivity test dispatched')
                            ->success()
                            ->body('Job inviato all’agent selezionato')
                            ->send();
                    }),
                Action::make('full_discovery')
                    ->label('Full Discovery')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        app(\App\Actions\Agent\DispatchDeviceJobAction::class)->execute($record, 'discovery_deep', $record->scan_agent_id, auth()->id());
                        \Filament\Notifications\Notification::make()
                            ->title('Full discovery dispatched')
                            ->body('Il job discovery_deep è stato inviato all’agent.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(DeviceExporter::class),
                    BulkAction::make('restore_lifecycle')
                        ->label('Restore (Active)')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            foreach ($records as $record) {
                                $record->markSeen();
                                $record->save();
                            }
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
