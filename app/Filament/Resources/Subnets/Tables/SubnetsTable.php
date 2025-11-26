<?php

namespace App\Filament\Resources\Subnets\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubnetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // 1. Status LED
                IconColumn::make('last_scan_status')
                    ->label('')
                    // 'heroicon-s-circle' doesn't exist in the heroicon set -> use a valid neutral icon
                    ->icon('heroicon-s-question-mark-circle')
                    ->color(fn ($state) => match ($state) {
                        'success' => 'success',
                        'warning' => 'warning',
                        'error', 'danger' => 'danger',
                        'running' => 'info',
                        default => 'gray',
                    })
                    ->tooltip(fn ($record) => $record->last_scan_at ? 'Last scan: ' . $record->last_scan_at : 'Never scanned')
                    ->extraAttributes(fn ($state) => $state === 'running' ? ['class' => 'animate-pulse'] : []),
                
                // 2. Subnet (CIDR format)
                TextColumn::make('subnet')
                    ->label('Subnet')
                    ->formatStateUsing(function ($record) {
                        $ipService = new \App\Services\IpConversionService();
                        $ip = $ipService->fromDecimal($record->subnet);
                        return $ip . '/' . $record->mask;
                    })
                    ->searchable()
                    ->sortable(),
                
                // 3. Description
                TextColumn::make('description')
                    ->limit(30)
                    ->searchable()
                    ->toggleable(),
                
                // 4. VLAN
                TextColumn::make('vlan.number')
                    ->label('VLAN')
                    ->formatStateUsing(fn ($state, $record) => $state ? $state . ($record->vlan?->name ? ' - ' . $record->vlan->name : '') : '-')
                    ->placeholder('-')
                    ->sortable(),
                
                // 5. Section
                TextColumn::make('section.name')
                    ->label('Section')
                    ->sortable()
                    ->searchable(),
                
                // 6. Customer
                TextColumn::make('customer.title')
                    ->label('Customer')
                    ->sortable()
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),
                
                // 7. Master Subnet (hidden by default)
                TextColumn::make('masterSubnet.subnet')
                    ->label('Master Subnet')
                    ->formatStateUsing(function ($state) {
                        if (!$state) return '-';
                        $ipService = new \App\Services\IpConversionService();
                        return $ipService->fromDecimal($state);
                    })
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                // 8. Other columns (hidden by default)
                TextColumn::make('scanAgent.name')
                    ->label('Scan Agent')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('vrf.name')
                    ->label('VRF')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('location.name')
                    ->label('Location')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('device.hostname')
                    ->label('Device')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                IconColumn::make('allow_requests')
                    ->label('Allow Requests')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                IconColumn::make('ping_subnet')
                    ->label('Ping Enabled')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                IconColumn::make('discover_subnet')
                    ->label('Discovery')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                IconColumn::make('is_folder')
                    ->label('Folder')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                IconColumn::make('is_full')
                    ->label('Full')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                IconColumn::make('is_pool')
                    ->label('Pool')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('threshold')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('firewall_address_object')
                    ->label('Firewall Object')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('last_scan')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('last_discovery')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(
                fn ($record): string => \App\Filament\Resources\Subnets\Pages\ViewSubnet::getUrl([$record])
            )
            ->filters([
                //
            ])
            ->actions([
                Action::make('scan')
                    ->icon('heroicon-o-play')
                    ->form([
                        \Filament\Forms\Components\Checkbox::make('debug_mode')
                            ->label('Show Output Log'),
                    ])
                    ->action(function (\App\Models\Subnet $record, array $data) {
                        // 1. Set running
                        $record->update(['last_scan_status' => 'running']);
                        
                        // 2. Run Fping
                        $wrapper = new \App\Services\Scanners\FpingWrapper();
                        try {
                            // Construct CIDR
                            $cidr = $record->subnet . '/' . $record->mask;
                            $ips = $wrapper->scanSubnet($cidr);
                            $output = implode("\n", $ips);
                            $status = 'success';
                        } catch (\Exception $e) {
                            $output = $e->getMessage();
                            $status = 'error';
                        }

                        // 3. Update status
                        $record->update([
                            'last_scan_status' => $status,
                            'last_scan_at' => now(),
                            'last_scan_output' => $output,
                        ]);

                        // 4. Debug handling
                        if ($data['debug_mode']) {
                             \Filament\Notifications\Notification::make()
                                ->title('Scan Debug Output')
                                ->body("```\n{$output}\n```")
                                ->persistent()
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('Scan Completed')
                                ->success()
                                ->send();
                        }
                    }),
                Action::make('edit')
                    ->icon('heroicon-o-pencil')
                    ->modalHeading('Edit Subnet')
                    ->form([
                        \Filament\Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->columnSpanFull(),

                        \Filament\Forms\Components\Select::make('section_id')
                            ->relationship('section','name')
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        \Filament\Forms\Components\Select::make('vlan_id')
                            ->options(function () {
                                return \App\Models\Vlan::query()
                                    ->orderBy('number')
                                    ->get()
                                    ->mapWithKeys(fn($v) => [$v->id => ($v->number ? ($v->number . ' - ') : '') . $v->name])
                                    ->toArray();
                            })
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        \Filament\Forms\Components\Select::make('customer_id')
                            ->relationship('customer', 'title')
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        \Filament\Forms\Components\Select::make('location_id')
                            ->relationship('location', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        \Filament\Forms\Components\Toggle::make('allow_requests'),

                        \Filament\Forms\Components\Toggle::make('is_folder'),

                        \Filament\Forms\Components\Toggle::make('is_full'),

                        \Filament\Forms\Components\Toggle::make('is_pool'),

                        \Filament\Forms\Components\TextInput::make('threshold')->numeric(),
                    ])
                    ->action(function (\App\Models\Subnet $record, array $data) {
                        $filtered = array_filter($data, fn($v) => !is_null($v) || $v === 0 || $v === false || $v === '');
                        $record->update($filtered);

                        \Filament\Notifications\Notification::make()
                            ->title('Subnet updated')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
