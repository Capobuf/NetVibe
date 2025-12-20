<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Settings\SecuritySettings;
use BackedEnum;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

class SecuritySettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Security';
    protected static ?string $title = 'Security Settings';
    protected static ?int $navigationSort = 101;
    protected static UnitEnum|string|null $navigationGroup = 'Administration';

    protected string $view = 'filament.pages.general-settings';

    public string $min_agent_version = '1.0.0';
    public string $min_supported_schema_version = '1.0.0';
    public bool $agent_tls_verify = false;
    public int $max_agent_payload_bytes = 20_000_000;

    protected function getFormSchema(): array
    {
        return [
            Section::make('Agent Security')
                ->schema([
                    TextInput::make('min_agent_version')
                        ->label('Minimum agent version')
                        ->helperText('Agents with a lower version will receive HTTP 426 (Upgrade Required).')
                        ->required(),

                    TextInput::make('min_supported_schema_version')
                        ->label('Minimum supported schema_version')
                        ->helperText('device_result payloads with a lower major version will be rejected (HTTP 426).')
                        ->required(),

                    Toggle::make('agent_tls_verify')
                        ->label('Verify TLS certificates for agent → device')
                        ->helperText('Enable to enforce TLS verification when agents call Mikrotik/REST/SNMP endpoints.'),

                    TextInput::make('max_agent_payload_bytes')
                        ->label('Max agent payload size (bytes, after compression)')
                        ->numeric()
                        ->minValue(1_000_000)
                        ->helperText('Hard limit for compressed job results (coerente con il limite PDL di ~20MB).'),
                ])
                ->columns(1),
        ];
    }

    public function mount(): void
    {
        /** @var SecuritySettings $settings */
        $settings = app(SecuritySettings::class);

        $this->form->fill([
            'min_agent_version' => $settings->min_agent_version,
            'min_supported_schema_version' => $settings->min_supported_schema_version,
            'agent_tls_verify' => $settings->agent_tls_verify,
            'max_agent_payload_bytes' => $settings->max_agent_payload_bytes,
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        /** @var SecuritySettings $settings */
        $settings = app(SecuritySettings::class);
        $settings->min_agent_version = (string) ($state['min_agent_version'] ?? $settings->min_agent_version);
        $settings->min_supported_schema_version = (string) ($state['min_supported_schema_version'] ?? $settings->min_supported_schema_version);
        $settings->agent_tls_verify = (bool) ($state['agent_tls_verify'] ?? $settings->agent_tls_verify);
        $settings->max_agent_payload_bytes = (int) ($state['max_agent_payload_bytes'] ?? $settings->max_agent_payload_bytes);

        $settings->save();

        Notification::make()
            ->success()
            ->title('Security settings saved')
            ->send();
    }
}
