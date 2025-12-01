<?php

namespace App\Filament\Pages;

use Filament\Forms;
use UnitEnum;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ColorPicker;
use Filament\Schemas\Components\Section;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;

class GeneralSettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    // Keep type compatibility with Filament\Pages\Page which allows enums or strings
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Settings';
    protected static ?string $title = 'Global Settings';
    protected static ?int $navigationSort = 100;

    // Group under Administration for global configuration
    protected static UnitEnum|string|null $navigationGroup = 'Administration';
    protected string $view = 'filament.pages.general-settings';

    /** @var string[] form properties */
    public string $site_name = '';
    public string $site_color = '#0ea5a4';
    public string $agent_alert_level = 'ERROR';
    public string $time_display_format = 'relative';
    public string $app_url = 'http://localhost';
    public bool $force_https = false;

    protected function getFormSchema(): array
    {
        // Wrap fields in a Section to ensure proper spacing and avoid UI button crowding
        return [
            Section::make('General')->schema([
                TextInput::make('site_name')
                    ->label('Site name')
                    ->required(),

                ColorPicker::make('site_color')
                    ->label('Site color')
                    ->columnSpanFull(),

                Forms\Components\Select::make('time_display_format')
                    ->label('Date/Time Display Format')
                    ->options([
                        'relative' => 'Relative (e.g. 2 hours ago)',
                        'precise' => 'Precise (Y-m-d H:i:s)',
                    ])
                    ->default('relative')
                    ->required(),

                Forms\Components\Select::make('agent_alert_level')
                    ->label('Agent Critical Alert Level')
                    ->options([
                        'WARNING' => 'Warning & Above',
                        'ERROR' => 'Error & Above',
                        'CRITICAL' => 'Critical Only',
                        'off' => 'Disabled (No Alerts)',
                    ])
                    ->default('ERROR')
                    ->required()
                    ->helperText('Trigger a dashboard notification when an agent reports an issue of this level or higher'),
                
                    TextInput::make('app_url')
                        ->label('Application URL')
                        ->url()
                        ->required(),

                    Forms\Components\Toggle::make('force_https')
                        ->label('Force HTTPS Schema')
                        ->helperText('Enable this if you are behind an SSL Proxy'),
            ])->columns(1),
        ];
    }

    public function mount(): void
    {
        $settings = app(GeneralSettings::class);

        $this->form->fill([
            'site_name' => $settings->site_name,
            'site_color' => $settings->site_color,
            'agent_alert_level' => $settings->agent_alert_level,
            'time_display_format' => $settings->time_display_format ?? 'relative',
            'app_url' => $settings->app_url ?? 'http://localhost',
            'force_https' => $settings->force_https ?? false,
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $settings = app(GeneralSettings::class);
        $settings->site_name = $state['site_name'] ?? $settings->site_name;
        $settings->site_color = $state['site_color'] ?? $settings->site_color;
        $settings->agent_alert_level = $state['agent_alert_level'] ?? $settings->agent_alert_level;
        $settings->time_display_format = $state['time_display_format'] ?? $settings->time_display_format;
        $settings->app_url = $state['app_url'] ?? $settings->app_url;
        $settings->force_https = $state['force_https'] ?? $settings->force_https;

        $settings->save();

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }
}
