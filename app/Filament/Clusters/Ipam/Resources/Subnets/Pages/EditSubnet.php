<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\Subnets\Pages;

use App\Filament\Clusters\Ipam\Resources\Subnets\SubnetResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

use App\Services\IpConversionService;
use App\Jobs\IpPrefillJob;
use App\Models\IpAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditSubnet extends EditRecord
{
    protected static string $resource = SubnetResource::class;
    
        protected function getHeaderActions(): array
        {
            return [
                Action::make('migrate_cidr')
                    ->label('Migrate/Resize CIDR')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->color('warning')
                    ->modalWidth('lg')
                    ->form([
                        TextInput::make('new_cidr')
                            ->label('New CIDR')
                            ->required(),

                        Radio::make('strategy')
                            ->label('Strategy')
                            ->required()
                            ->options([
                                'prune' => 'Shrink & Prune',
                                'remap' => 'Renumber / Move',
                                'wipe' => 'Wipe & Reset',
                            ])
                            ->helperText('Prune deletes out-of-range IPs, Remap moves host offsets, Wipe removes all IPs.'),
                    ])
                    ->action(function (array $data) {
                        $ipService = app(IpConversionService::class);

                        $record = $this->getRecord();

                        $oldCidr = $record->cidr;
                        if ($data['new_cidr'] === $oldCidr) {
                            Notification::make()->warning()->title('No change')->body('CIDR is identical to current value')->send();
                            return;
                        }

                        try {
                            $newParsed = $ipService->parseCidr($data['new_cidr']);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Invalid CIDR')->body('Please provide a valid CIDR')->send();
                            return;
                        }

                        $newSubnetDecimal = $newParsed['subnet'];
                        $newMask = $newParsed['mask'];

                        $oldSubnetDecimal = (string) $record->subnet;
                        $oldMask = (int) $record->mask;

                        DB::transaction(function () use ($record, $data, $ipService, $newSubnetDecimal, $newMask, $oldSubnetDecimal, $oldMask) {
                            $ips = $record->ipAddresses()->get();

                            if ($data['strategy'] === 'wipe') {
                                IpAddress::where('subnet_id', $record->getKey())->delete();
                            }

                            if ($data['strategy'] === 'prune') {
                                foreach ($ips as $ip) {
                                    if (! $ipService->isIpInSubnet($ip->ip_addr, $newSubnetDecimal, $newMask)) {
                                        $ip->delete();
                                    }
                                }
                            }

                            if ($data['strategy'] === 'remap') {
                                foreach ($ips as $ip) {
                                    // Calculate offset and apply to new network base
                                    $offset = bcsub((string) $ip->ip_addr, (string) $oldSubnetDecimal);
                                    $newIpDecimal = bcadd((string) $newSubnetDecimal, $offset);

                                    // Safety check: ensure fits new range
                                    if ($ipService->isIpInSubnet((string) $newIpDecimal, $newSubnetDecimal, $newMask)) {
                                        // Check for collisions
                                        $exists = IpAddress::where('subnet_id', $record->getKey())
                                            ->where('ip_addr', (string) $newIpDecimal)
                                            ->where('id', '!=', $ip->getKey())
                                            ->exists();

                                        if ($exists) {
                                            // collision - remove original to avoid duplicates
                                            Log::warning('remap collision, deleting original', ['ip_id' => $ip->getKey(), 'new_ip' => $newIpDecimal]);
                                            $ip->delete();
                                        } else {
                                            $ip->update(['ip_addr' => (string) $newIpDecimal]);
                                        }
                                    } else {
                                        // Out of bounds - delete
                                        Log::warning('remap out-of-bounds, deleting', ['ip_id' => $ip->getKey(), 'ip' => $ip->ip_addr]);
                                        $ip->delete();
                                    }
                                }
                            }

                            // Finally update subnet's CIDR via attribute
                            $record->cidr = $ipService->toCidr($newSubnetDecimal, $newMask);
                            $record->save();

                            // 🚀 Regenerate IPs for the updated subnet so the UI reflects the new range.
                            // The IpPrefillJob will create any missing IPs for this subnet.
                            IpPrefillJob::dispatch($record);
                        });

                        Notification::make()->success()->title('Subnet migrated')->body('CIDR migration completed')->send();
                    }),
            ];
        }
}

