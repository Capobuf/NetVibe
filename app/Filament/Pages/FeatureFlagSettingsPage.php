<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Settings\FeatureFlagSettings;
use BackedEnum;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

class FeatureFlagSettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static BackedEnum|string|null $navigationIcon = null;
    protected static ?string $navigationLabel = 'Feature Flags';
    protected static ?string $title = 'Feature Flags';
    protected static ?int $navigationSort = 102;
    protected static UnitEnum|string|null $navigationGroup = 'Administration';

    protected string $view = 'filament.pages.general-settings';

    public bool $snmp_fase1 = true;
    public bool $chunking = true;
    public bool $gzip = true;

    protected function getFormSchema(): array
    {
        return [
            Section::make('Feature Flags')
                ->schema([
                    Toggle::make('snmp_fase1')
                        ->label('Enable SNMP Fase 1')
                        ->helperText('Controlla l’attivazione della pipeline SNMP Fase 1 come definita nel PDL.'),

                    Toggle::make('chunking')
                        ->label('Enable payload chunking')
                        ->helperText('Abilita il supporto al chunking per i payload device_result di grandi dimensioni.'),

                    Toggle::make('gzip')
                        ->label('Enable gzip compression')
                        ->helperText('Abilita la compressione gzip per i job result dell’agent.'),
                ])
                ->columns(1),
        ];
    }

    public function mount(): void
    {
        /** @var FeatureFlagSettings $settings */
        $settings = app(FeatureFlagSettings::class);

        $this->form->fill([
            'snmp_fase1' => $settings->snmp_fase1,
            'chunking' => $settings->chunking,
            'gzip' => $settings->gzip,
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        /** @var FeatureFlagSettings $settings */
        $settings = app(FeatureFlagSettings::class);
        $settings->snmp_fase1 = (bool) ($state['snmp_fase1'] ?? $settings->snmp_fase1);
        $settings->chunking = (bool) ($state['chunking'] ?? $settings->chunking);
        $settings->gzip = (bool) ($state['gzip'] ?? $settings->gzip);

        $settings->save();

        Notification::make()
            ->success()
            ->title('Feature flags saved')
            ->send();
    }
}

