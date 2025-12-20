<?php

namespace App\Filament\Clusters\Racks\Resources\Racks\RelationManagers;

use App\Models\Device;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\CreateAction;
use Filament\Actions\Action;
// Edit/Delete handled via custom in-relation-manager Actions so tests can mount them
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class DevicesRelationManager extends RelationManager
{
    protected static string $relationship = 'devices';

    protected static ?string $recordTitleAttribute = 'hostname';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                // use virtual 'name' attribute which maps to hostname for devices
                TextColumn::make('name')->label('Hostname')->searchable()->sortable(),
                // device model attribute (model) for clearer identification
                TextColumn::make('deviceModel.model')->label('Model')->searchable()->sortable(),
                // show customer only when not in tenant mode
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->hidden(fn () => ! empty(session('tenant_id')))
                    ->searchable()
                    ->sortable(),
                // device status if present — display as badge
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ?? 'Unknown')
                    ->color(fn ($state) => match (strtolower((string)$state)) {
                        'active' => 'success',
                        'offline', 'down' => 'danger',
                        default => 'secondary',
                    })
                    ->sortable(),
                // rack position: show starting U
                TextColumn::make('rack_position')->label('U Start')->sortable(),
            ])
            ->headerActions([
                // Create a new device pre-associated to this rack and customer
                CreateAction::make()
                    ->slideOver()
                    ->label('Create device')
                    ->form(\App\Filament\Clusters\Racks\Resources\Devices\Schemas\DeviceForm::schema())
                    ->mutateFormDataUsing(function (array $data) {
                        // force association to the current owner record (rack)
                        $owner = $this->getOwnerRecord();
                        $data['rack_id'] = $owner->id;
                        // if rack has a customer, ensure it's set on device
                        if (! empty($owner->customer_id)) {
                            $data['customer_id'] = $owner->customer_id;
                        }

                        return $data;
                    })
                    ->relationship(fn () => $this->getRelationship()),

                // Mount an existing (orphan) device onto this rack
                Action::make('mount_existing')
                    ->label('Mount Existing Device')
                    ->form([
                        \Filament\Forms\Components\Select::make('device_id')
                            ->label('Device')
                            ->options(function () {
                                $owner = $this->getOwnerRecord();
                                $q = \App\Models\Device::query()->whereNull('rack_id');
                                if (! empty($owner->customer_id)) {
                                    $q->where('customer_id', $owner->customer_id);
                                }

                                return $q->orderBy('hostname')->pluck('hostname', 'id')->toArray();
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $owner = $this->getOwnerRecord();

                        $device = Device::find($data['device_id'] ?? null);
                        if (! $device) {
                            Notification::make()->title('Device not found')->danger()->send();
                            return;
                        }

                        $device->update(['rack_id' => $owner->id]);

                        Notification::make()->title('Mounted device')->success()->send();
                    }),
            ])
            ->actions([
                // Use a custom in-place edit action inside the relation manager so
                // it behaves consistently and is mountable in tests.
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->slideOver()
                    ->form(\App\Filament\Clusters\Racks\Resources\Devices\Schemas\DeviceForm::schema())
                    ->action(function (array $data, Device $record) {
                        // Persist the submitted form data to the device record
                        $record->update($data);

                        Notification::make()->title('Device updated')->success()->send();
                    }),

                Action::make('detach')
                    ->label('Unmount')
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Device $record) {
                        $record->update([
                            'rack_id' => null,
                            'rack_position' => null,
                        ]);

                        Notification::make()->title('Device unmounted')->success()->send();
                    }),

                Action::make('delete')
                    ->label('Delete')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Device $record) {
                        $record->delete();

                        Notification::make()->title('Device deleted')->success()->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('unmount_selected')
                        ->label('Unmount Selected')
                        ->icon('heroicon-o-arrow-right-start-on-rectangle')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $ids = $records->pluck('id')->all();
                            Device::whereIn('id', $ids)->update([
                                'rack_id' => null,
                                'rack_position' => null,
                            ]);
                            Notification::make()->title('Devices unmounted')->success()->send();
                        }),
                ])
            ])
            ->searchable();
    }
}
