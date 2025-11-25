<?php

namespace App\Filament\Pages;

use Filament\Forms;
use UnitEnum;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ColorPicker;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;

class GeneralSettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    // Keep type compatibility with Filament\Pages\Page which allows enums or strings
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Settings';
    protected static ?string $title = 'Global Settings';
    protected static ?int $navigationSort = 999;

    // keep this page isolated at the bottom of the navigation
    protected static UnitEnum|string|null $navigationGroup = null;
    protected string $view = 'filament.pages.general-settings';

    /** @var string[] form properties */
    public string $site_name = '';
    public int $scan_ping_timeout = 1000;
    public string $site_color = '#0ea5a4';

    protected function getFormSchema(): array
    {
        return [
            TextInput::make('site_name')
                ->label('Site name')
                ->required(),

            TextInput::make('scan_ping_timeout')
                ->label('Scan ping timeout (ms)')
                ->numeric()
                ->required(),

            ColorPicker::make('site_color')
                ->label('Site color')
                ->columnSpanFull(),
        ];
    }

    public function mount(): void
    {
        $settings = app(GeneralSettings::class);

        $this->form->fill([
            'site_name' => $settings->site_name,
            'scan_ping_timeout' => $settings->scan_ping_timeout,
            'site_color' => $settings->site_color,
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $settings = app(GeneralSettings::class);
        $settings->site_name = $state['site_name'] ?? $settings->site_name;
        $settings->scan_ping_timeout = (int) ($state['scan_ping_timeout'] ?? $settings->scan_ping_timeout);
        $settings->site_color = $state['site_color'] ?? $settings->site_color;

        $settings->save();

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }
}
