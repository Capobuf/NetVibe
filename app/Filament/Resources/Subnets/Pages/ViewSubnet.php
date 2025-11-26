<?php

namespace App\Filament\Resources\Subnets\Pages;

use App\Filament\Resources\Subnets\SubnetResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use App\Filament\Resources\Subnets\RelationManagers\IpAddressesRelationManager;
use Filament\Notifications\Notification;

class ViewSubnet extends ViewRecord
{
    protected static string $resource = SubnetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('scan')
                ->icon('heroicon-o-play')
                ->form([
                    \Filament\Forms\Components\Checkbox::make('debug_mode')
                        ->label('Show Output Log'),
                ])
                ->action(function (array $data) {
                    $record = $this->getRecord();
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
                ->action(function (array $data) {
                    $record = $this->getRecord();
                    // Only update provided keys
                    $filtered = array_filter($data, fn($v) => !is_null($v) || $v === 0 || $v === false || $v === '' );
                    $record->update($filtered);

                    Notification::make()
                        ->title('Subnet updated')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Ensure this view page will only display the Subnet's ipAddresses relation manager
     * (resource defines the relation managers by default, but make explicit here).
     */
    protected function getAllRelationManagers(): array
    {
        return [
            IpAddressesRelationManager::class,
        ];
    }
}
