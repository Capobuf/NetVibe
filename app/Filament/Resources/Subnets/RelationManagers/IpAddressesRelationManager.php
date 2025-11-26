<?php

namespace App\Filament\Resources\Subnets\RelationManagers;

use App\Services\IpConversionService;
use App\Services\Scanners\FpingWrapper;
use Filament\Forms;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
// IMPORTS ESPLICITI PER LE AZIONI
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
// IMPORTS ESPLICITI PER LE COLONNE
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

class IpAddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'ipAddresses';

    protected static ?string $recordTitleAttribute = 'ip_addr';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('ip_addr')
                    ->required()
                    ->label('IP Address')
                    ->formatStateUsing(function ($state) {
                        $ipService = app(IpConversionService::class);
                        $address = $ipService->fromDecimal($state);
                        return $address ? (string)$address : null;
                    })
                    ->dehydrateStateUsing(function ($state) {
                        $ipService = app(IpConversionService::class);
                        return $ipService->toDecimal((string) $state);
                    }),

                TextInput::make('hostname')->label('Hostname'),
                TextInput::make('mac')->label('MAC Address'),
                
                Select::make('state')
                    ->options([
                        0 => 'Not Verified',
                        1 => 'Offline',
                        2 => 'Used',
                        3 => 'Reserved',
                        4 => 'DHCP',
                    ])
                    ->default(2),

                Textarea::make('description')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ip_addr')
            ->defaultSort('ip_addr', 'asc')
            ->columns([
                IconColumn::make('state')
                    // Show a distinct icon for empty/not-verified (state = 0)
                    ->icon(fn ($state) => match ((int)$state) {
                        0 => 'heroicon-o-minus-circle',
                        // 'heroicon-s-circle' is not a valid heroicon name and caused SvgNotFound.
                        // Use a neutral, existing icon instead.
                        default => 'heroicon-s-question-mark-circle',
                    })
                    ->color(fn ($state) => match ((int)$state) {
                        0 => 'gray',
                        1 => 'danger',
                        2 => 'success',
                        3 => 'warning',
                        4 => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('ip_addr')
                    ->label('IP Address')
                    ->formatStateUsing(fn ($state) => app(IpConversionService::class)->fromDecimal($state))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('hostname')->searchable(),
                TextColumn::make('description')->limit(30),
                TextColumn::make('last_seen')
                    ->label('Last seen')
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        // Our DB migration historically set a default 1970 timestamp for last_seen.
                        // Treat both null and the 1970 placeholder as "Never" in the UI.
                        if (empty($state) || $state === '1970-01-01 00:00:01' || str_contains((string) $state, '1970')) {
                            return 'Never';
                        }

                        // Filament's dateTime() would format automatically, but since we're returning strings
                        // here when it's 'Never', leave everything else to Carbon via formatting
                        try {
                            return \Illuminate\Support\Facades\Date::parse($state)->toDayDateTimeString();
                        } catch (\Throwable $e) {
                            return (string) $state;
                        }
                    }),
            ])
            ->headerActions([
                CreateAction::make(), // NESSUN PREFISSO

                Action::make('populate')
                    ->icon('heroicon-o-table-cells')
                    ->label('Pre-fill IPs')
                    ->requiresConfirmation()
                    ->action(function (IpAddressesRelationManager $livewire) {
                        $subnet = $livewire->getOwnerRecord();

                        try {
                            $service = app(\App\Services\IpPrefillService::class);
                            $result = $service->prefill($subnet);

                            Notification::make()
                                ->title('Pre-fill completato')
                                ->body("{$result['inserted']} indirizzi IP creati come state=0")
                                ->success()
                                ->send();
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Pre-fill fallito: ' . $e->getMessage())->danger()->send();
                        }
                    }),
                
                Action::make('scan_subnet')
                    ->icon('heroicon-o-play')
                    ->label('Scan Subnet')
                    ->form([
                        Checkbox::make('debug_mode')->label('Show Log'),
                    ])
                    ->action(function (array $data, IpAddressesRelationManager $livewire) {
                        $subnet = $livewire->getOwnerRecord();
                        $subnet->update(['last_scan_status' => 'running']);
                        
try {
                            $ipService = app(IpConversionService::class);
                            
                            // Conversione sicura da Decimal a Stringa IP per fping
                            $subnetIp = $ipService->fromDecimal($subnet->subnet);
                            $baseIp = $subnetIp ? (string)$subnetIp : '0.0.0.0';
                            $cidr = $baseIp . '/' . $subnet->mask;

                            // Esecuzione Scansione
                            $wrapper = new FpingWrapper();
                            $ips = $wrapper->scanSubnet($cidr);
                            
                            // Preparazione statistiche
                            $output = implode("\n", $ips);
                            $newCount = 0;
                            $updatedCount = 0;

                            // Logica di Persistenza (Salvataggio nel DB)
                            foreach ($ips as $ipString) {
                                // 1. Converti l'IP scansionato (stringa) in decimale per il DB
                                $decimalIp = $ipService->toDecimal($ipString);

                                // 2. Cerca se esiste già nella relazione ipAddresses() della subnet
                                $ipRecord = $subnet->ipAddresses()->where('ip_addr', $decimalIp)->first();

                                if ($ipRecord) {
                                    // UPDATE: Se esiste, aggiorna stato a 'Used' (2) e last_seen
                                    $ipRecord->update([
                                        'state' => 2, 
                                        'last_seen' => now(),
                                    ]);
                                    $updatedCount++;
                                } else {
                                    // CREATE: Se non esiste, crea nuovo record
                                    $subnet->ipAddresses()->create([
                                        'ip_addr' => $decimalIp,
                                        'state' => 2, // Used
                                        'last_seen' => now(),
                                        'description' => 'Discovered via Scan',
                                        // Tenta risoluzione DNS inversa (timeout basso gestito da OS solitamente)
                                        'hostname' => gethostbyaddr($ipString) !== $ipString ? gethostbyaddr($ipString) : null,
                                    ]);
                                    $newCount++;
                                }
                            }

                            // Aggiungi riepilogo all'output per il log
                            $output .= "\n\n--------------------------\n";
                            $output .= "Result: {$newCount} New, {$updatedCount} Updated.";
                            
                            $status = 'success';

                        } catch (\Throwable $e) {
                            $output = $e->getMessage();
                            $status = 'error';
                        }

                        $subnet->update([
                            'last_scan_status' => $status,
                            'last_scan_at' => now(),
                            'last_scan_output' => $output,
                        ]);

                        Notification::make()
                            ->title($status === 'success' ? 'Scan Completed' : 'Scan Failed')
                            ->body($data['debug_mode'] ? $output : '')
                            ->send();
                    }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}