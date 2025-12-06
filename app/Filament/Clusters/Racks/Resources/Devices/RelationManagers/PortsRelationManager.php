<?php

namespace App\Filament\Clusters\Racks\Resources\Devices\RelationManagers;

use App\Models\CableType;
use App\Models\Device;
use App\Models\Link;
use App\Models\Port;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

class PortsRelationManager extends RelationManager
{
    protected static string $relationship = 'ports';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\Select::make('type')
                ->options(['rj45' => 'RJ45', 'sfp' => 'SFP', 'fiber' => 'Fiber'])
                ->default('rj45'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            // Note: Filament documentation does not document "contentGrid" for table()
            // Use standard columns and actions; layout can be done using ViewColumn or
            // a custom column view if a card-like representation is required.
            ->columns([
                TextColumn::make('name')
                    ->weight('bold')
                    ->description(fn (Port $record) => strtoupper($record->type))
                    ->formatStateUsing(fn ($state, Port $record) => $state ?? $record->name),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'down' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->actions([
                TableAction::make('connect')
                    ->icon('heroicon-m-link')
                    ->color('primary')
                    ->hidden(fn (Port $record) => $record->status === 'active')
                    ->form([
                        Forms\Components\Select::make('target_device_id')
                            ->label('Target Device')
                            ->options(function () {
                                $owner = $this->getOwnerRecord();

                                // Only include devices that actually have free (down) ports available
                                $devices = Device::where('id', '!=', $owner->id)
                                    ->whereHas('ports', fn($q) => $q->where('status', 'down'))
                                    ->withCount(['ports as free_ports_count' => fn($q) => $q->where('status', 'down')])
                                    ->orderBy('hostname')
                                    ->get();

                                return $devices->mapWithKeys(fn($d) => [$d->id => $d->hostname . ' (' . ($d->free_ports_count ?? 0) . ' free)'])->toArray();
                            })
                            ->searchable()
                            ->reactive()
                            ->required(),

                        Forms\Components\Select::make('target_port_id')
                            ->label('Target Port')
                            ->options(function ($get) {
                                $targetDeviceId = $get('target_device_id');

                                if (! $targetDeviceId) {
                                    return [];
                                }

                                return Port::where('device_id', $targetDeviceId)
                                    ->where('status', 'down')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->searchable()
                            ->required()
                            ->disabled(fn ($get) => ! $get('target_device_id')),

                        Forms\Components\Select::make('cable_type_id')
                            ->label('Cable Type')
                            ->options(CableType::orderBy('name')->pluck('name', 'id')->toArray())
                            ->preload()
                            ->required(),
                    ])
                    ->action(function (Port $record, array $data): void {
                        // Protect against duplicates and invalid selections
                        $targetPortId = $data['target_port_id'] ?? null;

                        if (! $targetPortId) {
                            Notification::make()->title('Missing target port')->danger()->send();
                            return;
                        }

                        if ($targetPortId === $record->id) {
                            Notification::make()->title('Cannot connect to itself')->danger()->send();
                            return;
                        }

                        try {
                            app(\App\Services\Physical\ManageLinkService::class)->connect($record, Port::find($targetPortId), $data['cable_type_id'] ?? null);

                            Notification::make()->title('Connected')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                        
                    }),

                TableAction::make('disconnect')
                    ->icon('heroicon-m-scissors')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Port $record) => $record->status === 'active')
                    ->action(function (Port $record) {
                        try {
                            app(\App\Services\Physical\ManageLinkService::class)->disconnect($record);

                            Notification::make()->title('Disconnected')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
