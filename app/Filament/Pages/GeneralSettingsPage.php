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
            ])->columns(1),
        ];
    }

    public function mount(): void
    {
        $settings = app(GeneralSettings::class);

        $this->form->fill([
            'site_name' => $settings->site_name,
            'site_color' => $settings->site_color,
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $settings = app(GeneralSettings::class);
        $settings->site_name = $state['site_name'] ?? $settings->site_name;
        $settings->site_color = $state['site_color'] ?? $settings->site_color;

        $settings->save();

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }
}
