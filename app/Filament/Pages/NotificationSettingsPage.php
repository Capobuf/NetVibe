<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Pages\Page;
use UnitEnum;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section;
use App\Settings\NotificationSettings;

class NotificationSettingsPage extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-bell';
    protected static ?string $navigationLabel = 'Notifications';
    protected static ?string $title = 'Notifications Settings';
    protected static ?int $navigationSort = 105;
    protected static UnitEnum|string|null $navigationGroup = 'Administration';
    protected string $view = 'filament.pages.notification-settings';

    public bool $webhook_enabled = false;
    public ?string $webhook_url = null;

    public bool $telegram_enabled = false;
    public ?string $telegram_bot_token = null;
    public ?string $telegram_chat_id = null;

    // notify_on toggles
    public bool $notify_agent_offline = true;
    public bool $notify_agent_online = false;
    public bool $notify_discovery_error = true;
    public bool $notify_discovery_conflict = true;
    public bool $notify_backup_completed = true;
    public bool $notify_backup_failed = true;

    public int $rate_limit_seconds = 300;

    protected function getFormSchema(): array
    {
        return [
            Section::make('Webhooks & Telegram')->schema([
                Toggle::make('webhook_enabled')->label('Webhook enabled')->helperText('Enable/disable webhook POSTs (Fase 1)'),
                TextInput::make('webhook_url')->label('Webhook URL')->url()->placeholder('https://hooks.example.com/path')->helperText('Webhook receives POST JSON per PDL contract')->columnSpanFull(),

                Toggle::make('telegram_enabled')->label('Telegram enabled')->helperText('Fase 2: driver not active yet'),
                TextInput::make('telegram_bot_token')->label('Telegram bot token')->placeholder('123456:ABC-DEF')->columnSpanFull(),
                TextInput::make('telegram_chat_id')->label('Telegram chat id')->placeholder('@my_channel_or_chat_id')->columnSpanFull(),
            ])->columns(1),

            Section::make('Notify on Events')->schema([
                Grid::make(3)->schema([
                    Toggle::make('notify_agent_offline')->label('Agent offline')->inline(false),
                    Toggle::make('notify_agent_online')->label('Agent online')->inline(false),
                    Toggle::make('notify_discovery_error')->label('Discovery error')->inline(false),
                    Toggle::make('notify_discovery_conflict')->label('Discovery conflict')->inline(false),
                    Toggle::make('notify_backup_completed')->label('Backup completed')->inline(false),
                    Toggle::make('notify_backup_failed')->label('Backup failed')->inline(false),
                ]),
                TextInput::make('rate_limit_seconds')->label('Rate limit (seconds)')->numeric()->helperText('Minimum seconds between same event_type per tenant')->default(300),
            ])->columns(1),
        ];
    }

    public function mount(): void
    {
        $settings = app(NotificationSettings::class);

        $this->form->fill([
            'webhook_enabled' => $settings->webhook_enabled,
            'webhook_url' => $settings->webhook_url,
            'telegram_enabled' => $settings->telegram_enabled,
            'telegram_bot_token' => $settings->telegram_bot_token,
            'telegram_chat_id' => $settings->telegram_chat_id,

            'notify_agent_offline' => $settings->notify_on['agent_offline'] ?? true,
            'notify_agent_online' => $settings->notify_on['agent_online'] ?? false,
            'notify_discovery_error' => $settings->notify_on['discovery_error'] ?? true,
            'notify_discovery_conflict' => $settings->notify_on['discovery_conflict'] ?? true,
            'notify_backup_completed' => $settings->notify_on['backup_completed'] ?? true,
            'notify_backup_failed' => $settings->notify_on['backup_failed'] ?? true,

            'rate_limit_seconds' => $settings->rate_limit_seconds ?? 300,
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $settings = app(NotificationSettings::class);

        $settings->webhook_enabled = $state['webhook_enabled'] ?? false;
        $settings->webhook_url = $state['webhook_url'] ?? null;

        $settings->telegram_enabled = $state['telegram_enabled'] ?? false;
        $settings->telegram_bot_token = $state['telegram_bot_token'] ?? null;
        $settings->telegram_chat_id = $state['telegram_chat_id'] ?? null;

        $settings->notify_on = [
            'agent_offline' => $state['notify_agent_offline'] ?? true,
            'agent_online' => $state['notify_agent_online'] ?? false,
            'discovery_error' => $state['notify_discovery_error'] ?? true,
            'discovery_conflict' => $state['notify_discovery_conflict'] ?? true,
            'backup_completed' => $state['notify_backup_completed'] ?? true,
            'backup_failed' => $state['notify_backup_failed'] ?? true,
        ];

        $settings->rate_limit_seconds = $state['rate_limit_seconds'] ?? 300;

        $settings->save();

        Notification::make()->success()->title('Notification settings saved')->send();
    }
}
