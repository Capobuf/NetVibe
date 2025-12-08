<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Pages;

use Filament\Forms;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use App\Settings\DiscoverySettings;

class ManageDiscoverySettings extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    // Remove page-level icon so the Discovery group icon is used in the topbar
    protected static BackedEnum|string|null $navigationIcon = null;
    protected static ?string $navigationLabel = 'Settings';
    protected static ?string $title = 'Discovery Settings';
    protected static ?int $navigationSort = 10;

    // Detached from cluster so it appears in the Discovery top-nav group
    // protected static ?string $cluster = \App\Filament\Clusters\Discovery\DiscoveryCluster::class;

    // Ensure the page appears under the Discovery dropdown
    protected static string | \UnitEnum | null $navigationGroup = 'Discovery';

    protected string $view = 'filament.pages.discovery-settings';

    public int $default_scan_interval_minutes = 60;
    public bool $auto_discovery_enabled = true;
    public int $icmp_timeout_ms = 200;
    public int $icmp_retries = 1;
    public int $prune_logs_days = 3;

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Toggle::make('auto_discovery_enabled')
                ->label('Enable Auto-Discovery Globally')
                ->helperText('When enabled, new subnets will have auto-discovery enabled by default')
                ->columnSpanFull(),

            TextInput::make('default_scan_interval_minutes')
                ->label('Default scan interval')
                ->numeric()
                ->suffix('mins')
                ->required()
                ->helperText('Intervallo usato per le nuove subnet se non specificato.'),

            TextInput::make('icmp_timeout_ms')
                ->label('ICMP timeout')
                ->numeric()
                ->suffix('ms')
                ->required(),

            TextInput::make('icmp_retries')
                ->label('ICMP retries')
                ->numeric()
                ->required(),

            TextInput::make('prune_logs_days')
                ->label('Agent Logs Retention (Days)')
                ->numeric()
                ->minValue(1)
                ->maxValue(365)
                ->default(3)
                ->required()
                ->helperText('Automatically delete agent logs older than this many days'),
            TextInput::make('agent_polling_interval_minutes')
                ->label('Agent polling interval')
                ->numeric()
                ->suffix('mins')
                ->default(5)
                ->required()
                ->helperText('Frequenza (minuti) con cui l’agent interroga /api/v1/agent/jobs; usato anche per il config poll.'),
        ];
    }

    public function mount(): void
    {
        $settings = app(DiscoverySettings::class);

        $this->form->fill([
            'auto_discovery_enabled' => $settings->auto_discovery_enabled,
            'default_scan_interval_minutes' => $settings->default_scan_interval_minutes,
            'icmp_timeout_ms' => $settings->icmp_timeout_ms,
            'icmp_retries' => $settings->icmp_retries,
            'prune_logs_days' => $settings->prune_logs_days ?? 3,
            'agent_polling_interval_minutes' => $settings->agent_polling_interval_minutes ?? 5,
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $settings = app(DiscoverySettings::class);
        $settings->auto_discovery_enabled = (bool) ($state['auto_discovery_enabled'] ?? $settings->auto_discovery_enabled);
        $settings->default_scan_interval_minutes = (int) ($state['default_scan_interval_minutes'] ?? $settings->default_scan_interval_minutes);
        $settings->icmp_timeout_ms = (int) ($state['icmp_timeout_ms'] ?? $settings->icmp_timeout_ms);
        $settings->icmp_retries = (int) ($state['icmp_retries'] ?? $settings->icmp_retries);
        $settings->prune_logs_days = (int) ($state['prune_logs_days'] ?? $settings->prune_logs_days ?? 3);
        $settings->agent_polling_interval_minutes = (int) ($state['agent_polling_interval_minutes'] ?? $settings->agent_polling_interval_minutes ?? 5);

        $settings->save();

        Notification::make()->success()->title('Settings saved')->send();
    }
}
