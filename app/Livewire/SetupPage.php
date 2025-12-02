<?php

namespace App\Livewire;

use Livewire\Component;
use Filament\Forms;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step as WizardStep;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use App\Services\SystemHealthCheckService;
use App\Services\EnvEditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema as IlluminateSchema;
use Throwable;

class SetupPage extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public array $healthCheckResults = [];
    public ?string $dbTestStatus = null;
    public bool $migrating = false;
    public bool $migrationSuccess = false;
    public bool $setupCompleted = false;
    // Controls whether the user explicitly confirmed a destructive DB reset
    public bool $confirmFullReset = false;

    // DB Config
    public string $db_host = '';
    public string $db_port = '';
    public string $db_database = '';
    public string $db_username = '';
    public string $db_password = '';

    // Admin Account
    public string $user_name = '';
    public string $user_email = '';
    public string $password = '';
    public string $password_confirmation = '';

    // Site Config
    public string $site_name = 'NetVibe';
    public string $site_color = '#0ea5a4';
    public bool $install_demo_data = false;

    public function mount(SystemHealthCheckService $service): void
    {
        $this->healthCheckResults = $service->checkAll();

        // Load current DB config
        $this->db_host = config('database.connections.mysql.host', 'db');
        $this->db_port = config('database.connections.mysql.port', '3306');
        $this->db_database = config('database.connections.mysql.database', 'netvibe');
        $this->db_username = config('database.connections.mysql.username', 'netvibe_user');
        // Do not pre-fill password for security, or maybe leave empty if user wants to keep existing
        // For UX, we might want to show it if it's in .env, but let's be safe.
        // Actually, for a setup wizard, pre-filling from env is helpful if they just want to verify.
        $this->db_password = config('database.connections.mysql.password', '');

        $this->form->fill([
            'db_host' => $this->db_host,
            'db_port' => $this->db_port,
            'db_database' => $this->db_database,
            'db_username' => $this->db_username,
            'db_password' => $this->db_password,
            'site_name' => $this->site_name,
            'site_color' => $this->site_color,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    // STEP 1: System Health
                    WizardStep::make('System Health')
                        ->schema([
                            Placeholder::make('health_checks')
                                ->content(view('livewire._setup_health', ['results' => $this->healthCheckResults]))
                        ])
                        ->afterValidation(function () {
                            // Block progress if critical checks fail?
                            // For now we allow proceeding but show warnings in UI
                        }),

                    // STEP 2: Database Configuration
                    WizardStep::make('Database')
                        ->schema([
                            TextInput::make('db_host')->label('Host')->required(),
                            TextInput::make('db_port')->label('Port')->required(),
                            TextInput::make('db_database')->label('Database Name')->required(),
                            TextInput::make('db_username')->label('Username')->required(),
                            TextInput::make('db_password')->label('Password')->password()->revealable(),
                            
                            Placeholder::make('db_actions')
                                ->label('Connection Status')
                                ->content(view('livewire._setup_db_config', [
                                    'status' => $this->dbTestStatus
                                ])),
                        ])
                        ->afterValidation(function () {
                            if ($this->dbTestStatus !== 'connected') {
                                // Optionally block if not connected, or just warn
                                // $this->addError('db_host', 'Please ensure database is connected before proceeding.');
                            }
                        }),

                    // STEP 3: Migrations
                    WizardStep::make('Migrations')
                        ->schema([
                            Placeholder::make('migrations_status')
                                ->content(view('livewire._setup_migrations', [
                                    'migrating' => $this->migrating,
                                    'success' => $this->migrationSuccess
                                ])),
                        ]),

                    // STEP 4: Admin Account
                    WizardStep::make('Admin Account')
                        ->schema([
                            TextInput::make('user_name')->label('Full Name')->required(),
                            TextInput::make('user_email')->label('Email')->email()->required(),
                            TextInput::make('password')->label('Password')->password()->confirmed()->required(),
                            TextInput::make('password_confirmation')->label('Confirm Password')->password()->required(),
                        ]),

                    // STEP 5: Configuration
                    WizardStep::make('Site Config')
                        ->schema([
                            TextInput::make('site_name')->label('Site Name')->default('NetVibe')->required(),
                            ColorPicker::make('site_color')->label('Site Color')->default('#0ea5a4'),
                            Toggle::make('install_demo_data')->label('Install Demo Data'),
                        ]),

                    // STEP 6: Summary
                    WizardStep::make('Summary')
                        ->schema([
                            Placeholder::make('summary_view')
                                ->content(view('livewire._setup_summary', [
                                    'data' => $this->all()
                                ])),
                        ]),
                ])
                ->persistStepInQueryString('step')
                ->submitAction(new \Illuminate\Support\HtmlString('
                        <button type="submit" 
                                wire:click="finishSetup" 
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-75 cursor-wait"
                                class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-primary bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 px-3 py-2 text-sm inline-grid shadow-sm gap-1.5">
                            <span wire:loading.remove wire:target="finishSetup">
                                Finish & Install
                            </span>
                            <span wire:loading wire:target="finishSetup" class="flex items-center gap-2">
                                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Installing...
                            </span>
                        </button>
                    '))
            ]);
    }

    public function testConnection(): void
    {
        $data = $this->form->getState();
        
        // Temporarily set config to test connection
        Config::set('database.connections.mysql_test', [
            'driver' => 'mysql',
            'host' => $data['db_host'],
            'port' => $data['db_port'],
            'database' => $data['db_database'],
            'username' => $data['db_username'],
            'password' => $data['db_password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        try {
            DB::connection('mysql_test')->getPdo();
            $this->dbTestStatus = 'connected';
            
            // If successful, save to .env immediately
            $this->saveDatabaseSettings($data);
            
        } catch (Throwable $e) {
            $this->dbTestStatus = 'cannot connect — ' . $e->getMessage();
        }
    }

    protected function saveDatabaseSettings(array $data): void
    {
        $editor = new EnvEditor();
        $editor->setKeys([
            'DB_HOST' => $data['db_host'],
            'DB_PORT' => $data['db_port'],
            'DB_DATABASE' => $data['db_database'],
            'DB_USERNAME' => $data['db_username'],
            'DB_PASSWORD' => $data['db_password'],
        ]);

        // Also update runtime config so migrations work immediately
        Config::set('database.connections.mysql.host', $data['db_host']);
        Config::set('database.connections.mysql.port', $data['db_port']);
        Config::set('database.connections.mysql.database', $data['db_database']);
        Config::set('database.connections.mysql.username', $data['db_username']);
        Config::set('database.connections.mysql.password', $data['db_password']);
        
        // Reconnect default connection
        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    public function runMigrations(): void
    {
        // Acquire a lock so only a single server/worker runs setup migrations at once.
        $lock = Cache::lock('netvibe.setup.migrations', 1200); // 20 minutes

        if (! $lock->get()) {
            \Filament\Notifications\Notification::make()
                ->title('Migration already in progress')
                ->body('Another migration or setup run is currently in progress. Try again later.')
                ->warning()
                ->send();
            return;
        }

        $this->migrating = true;
        try {
            // Default behaviour: run safe, incremental migrations. Only run a destructive
            // migrate:fresh when the user has explicitly confirmed (confirmFullReset === true).
            // This prevents accidental DB wipes from a single page request.

            $shouldReset = (bool) $this->confirmFullReset;

            // Extra safety for production-like environments
            if ($shouldReset && app()->environment('production')) {
                throw new \RuntimeException('Full database reset is blocked in production environment.');
            }

            if ($shouldReset) {
                // Destructive explicit reset
                Artisan::call('migrate:fresh', ['--force' => true]);
            } else {
                // Safe incremental migrations
                Artisan::call('migrate', ['--force' => true]);
            }

            $this->migrationSuccess = true;
            \Filament\Notifications\Notification::make()
                ->title('Database migrated successfully')
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->migrationSuccess = false;
            Log::error('Migration failed: ' . $e->getMessage());
            \Filament\Notifications\Notification::make()
                ->title('Migrations failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->migrating = false;
            // Always release the lock
            try { $lock->release(); } catch (\Throwable $_) {}
        }
    }

    public function finishSetup()
    {
        $data = $this->form->getState();

        try {
            // Create or update the Admin user in an idempotent way.
            if (! empty($data['user_email'])) {
                $existing = \App\Models\User::where('email', $data['user_email'])->first();
                if ($existing) {
                    // Update name and (optionally) password if provided
                    $existing->name = $data['user_name'] ?? $existing->name;
                    if (! empty($data['password'])) {
                        $existing->password = \Illuminate\Support\Facades\Hash::make($data['password']);
                    }
                    $existing->email_verified_at = $existing->email_verified_at ?: now();
                    $existing->save();
                    auth()->login($existing);
                } else {
                    $user = \App\Models\User::create([
                        'name' => $data['user_name'],
                        'email' => $data['user_email'],
                        'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
                        'email_verified_at' => now(),
                    ]);
                    auth()->login($user);
                }
            }

            // Save Site Settings - ensure all properties are set
            // Save Site Settings in a safe / defensive manner. The Settings repository may
            // not be available until migrations have run; guard with Schema checks and try/catch.

            if (IlluminateSchema::hasTable('settings')) {
                try {
                    $settings = app(\App\Settings\GeneralSettings::class);
                    // Only set properties that actually exist on GeneralSettings and protect
                    // from referencing removed/obsolete columns.
                    if (property_exists($settings, 'site_name')) {
                        $settings->site_name = $data['site_name'];
                    }
                    if (property_exists($settings, 'site_color')) {
                        $settings->site_color = $data['site_color'];
                    }

                    // Non-destructive defaults (idempotent helpers)
                    if (empty($settings->app_url) && property_exists($settings, 'app_url')) {
                        $settings->app_url = config('app.url', '');
                    }
                    if (! isset($settings->force_https) && property_exists($settings, 'force_https')) {
                        $settings->force_https = str_starts_with(config('app.url', ''), 'https://');
                    }
                    if (empty($settings->agent_alert_level) && property_exists($settings, 'agent_alert_level')) {
                        $settings->agent_alert_level = 'ERROR';
                    }
                    if (empty($settings->time_display_format) && property_exists($settings, 'time_display_format')) {
                        $settings->time_display_format = 'relative';
                    }

                    $settings->save();
                } catch (Throwable $e) {
                    // Settings save can fail during early bootstrap; log and continue without fatal error.
                    Log::warning('Setup could not persist GeneralSettings: ' . $e->getMessage());
                }
            } else {
                // settings table not available — skip gracefully and avoid referencing missing columns
                Log::info('Setup detected missing settings table — skipping GeneralSettings save.');
            }
            // Set other required properties to defaults if not already set
            if (empty($settings->app_url)) {
                $settings->app_url = config('app.url', '');
            }
            if (!isset($settings->force_https)) {
                $settings->force_https = str_starts_with(config('app.url', ''), 'https://');
            }
            if (empty($settings->agent_alert_level)) {
                $settings->agent_alert_level = 'ERROR';
            }
            if (empty($settings->time_display_format)) {
                $settings->time_display_format = 'relative';
            }
            $settings->save();

            // Install Demo Data
            if (! empty($data['install_demo_data'])) {
                // Only seed demo data when it looks safe to do so — simple heuristic: check at least
                // one application table exists and is empty so we don't duplicate or corrupt data.
                try {
                    if (IlluminateSchema::hasTable('subnets')) {
                        $count = DB::table('subnets')->count();
                        if ($count === 0) {
                            Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('Demo data skipped')
                                ->body('It appears this database already has data — demo seeder was skipped to avoid duplicates.')
                                ->warning()
                                ->send();
                        }
                    } else {
                        // Table not present — avoid seeding and warn
                        \Filament\Notifications\Notification::make()
                            ->title('Demo data skipped')
                            ->body('Seeder could not run because expected target tables were not found.')
                            ->warning()
                            ->send();
                    }
                } catch (Throwable $e) {
                    Log::error('Seeding demo data failed: ' . $e->getMessage());
                    \Filament\Notifications\Notification::make()
                        ->title('Seeding failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            }

            // Mark setup as complete to show the success screen
            $this->setupCompleted = true;

        } catch (Throwable $e) {
            Log::error('finishSetup failed: ' . $e->getMessage());
            \Filament\Notifications\Notification::make()
                ->title('Failed to finish setup')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function render()
    {
        return view('livewire.setup-page')->layout('layouts.setup');
    }
}
