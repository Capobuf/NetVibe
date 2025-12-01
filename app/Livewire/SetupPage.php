<?php

namespace App\Livewire;

use Livewire\Component;
use Filament\Forms;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use App\Services\SystemHealthCheckService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class SetupPage extends Component implements HasForms
{
    use InteractsWithForms;

    public array $healthCheckResults = [];
    public ?string $dbTestStatus = null;
    public bool $migrating = false;
    // form fields (state will also be handled by Filament form API)
    public string $user_name = '';
    public string $user_email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $site_name = 'Modern IPAM';
    public string $site_color = '#0ea5a4';
    public bool $install_demo_data = false;

    protected function getFormSchema(): array
    {
        return [
            Wizard::make([
                Wizard\Step::make('System Health')
                    ->schema([
                        Placeholder::make('health_checks')
                            ->content(view('livewire._setup_health', ['results' => $this->healthCheckResults]))
                    ]),

                Wizard\Step::make('Database')
                    ->schema([
                        Placeholder::make('db_status')
                            ->content(function () {
                                return $this->dbTestStatus ?? 'Not tested yet.';
                            }),
                    ]),

                Wizard\Step::make('Admin Account')
                    ->schema([
                        TextInput::make('user_name')->label('Full name')->required(),
                        TextInput::make('user_email')->label('Email')->email()->required(),
                        TextInput::make('password')->label('Password')->password()->confirmed()->required(),
                        TextInput::make('password_confirmation')->label('Confirm Password')->password()->required(),
                    ]),

                Wizard\Step::make('Configuration')
                    ->schema([
                        TextInput::make('site_name')->label('Site name')->default('Modern IPAM')->required(),
                        ColorPicker::make('site_color')->label('Site color')->default('#0ea5a4'),
                        Toggle::make('install_demo_data')->label('Install Demo Data (Recommended for testing)'),
                    ]),
            ]),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Wizard::make([
                Wizard\Step::make('System Health')
                    ->schema([
                        Placeholder::make('health_checks')
                            ->content(view('livewire._setup_health', ['results' => $this->healthCheckResults]))
                    ]),

                Wizard\Step::make('Database')
                    ->schema([
                        Placeholder::make('db_status')
                            ->content(function () {
                                return $this->dbTestStatus ?? 'Not tested yet.';
                            }),
                    ]),

                Wizard\Step::make('Admin Account')
                    ->schema([
                        TextInput::make('user_name')->label('Full name')->required(),
                        TextInput::make('user_email')->label('Email')->email()->required(),
                        TextInput::make('password')->label('Password')->password()->confirmed()->required(),
                        TextInput::make('password_confirmation')->label('Confirm Password')->password()->required(),
                    ]),

                Wizard\Step::make('Configuration')
                    ->schema([
                        TextInput::make('site_name')->label('Site name')->default('Modern IPAM')->required(),
                        ColorPicker::make('site_color')->label('Site color')->default('#0ea5a4'),
                        Toggle::make('install_demo_data')->label('Install Demo Data (Recommended for testing)'),
                    ]),
            ]),
        ]);
    }

    public function finishSetup()
    {
        $data = $this->form->getState();

        // Basic server-side validation
        if (empty($data['user_name'] ?? '') || empty($data['user_email'] ?? '') || empty($data['password'] ?? '')) {
            $this->dispatchBrowserEvent('notify', ['type' => 'error', 'message' => 'Please fill the Admin account fields before finishing.']);
            return;
        }

        try {
            // prevent creating duplicate admin if exists
            if (\App\Models\User::exists()) {
                $this->dispatchBrowserEvent('notify', ['type' => 'warning', 'message' => 'An admin user already exists — aborting.']);
                auth()->loginUsingId(1);
                redirect('/admin');
                return;
            }

            $user = \App\Models\User::create([
                'name' => $data['user_name'],
                'email' => $data['user_email'],
                'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
                'email_verified_at' => now(),
            ]);

            // Save settings
            $settings = app(\App\Settings\GeneralSettings::class);
            $settings->site_name = $data['site_name'] ?? $settings->site_name;
            $settings->site_color = $data['site_color'] ?? $settings->site_color;
            $settings->save();

            // Optional: install demo data
            if (!empty($data['install_demo_data'])) {
                Artisan::call('db:seed', ['--class' => 'DatabaseSeeder']);
            }

            auth()->login($user);
            return redirect('/admin');
        } catch (Throwable $e) {
            Log::error('finishSetup failed: ' . $e->getMessage());
            $this->dispatchBrowserEvent('notify', ['type' => 'error', 'message' => 'Failed to finish setup: '.$e->getMessage()]);
        }
    }

    public function mount(SystemHealthCheckService $service): void
    {
        $this->healthCheckResults = $service->checkAll();

        // initial DB test (non-fatal)
        try {
            // attempts a db connection
            \DB::connection()->getPdo();
            $this->dbTestStatus = 'connected';
        } catch (Throwable $e) {
            $this->dbTestStatus = 'cannot connect — ' . $e->getMessage();
        }

        $this->form->fill([]);
    }

    public function testConnection(): void
    {
        try {
            \DB::connection()->getPdo();
            $this->dbTestStatus = 'connected';
        } catch (Throwable $e) {
            $this->dbTestStatus = 'cannot connect — ' . $e->getMessage();
        }
    }

    public function runMigrations(): void
    {
        $this->migrating = true;
        try {
            // use migrate:fresh --seed as requested; allow long-running operations
            Artisan::call('migrate:fresh', ['--seed' => true]);
            $output = Artisan::output();
            Log::info('Migrations run by setup wizard: ' . substr($output, 0, 1000));
            $this->dispatchBrowserEvent('notify', ['type' => 'success', 'message' => 'Migrations ran successfully']);
        } catch (Throwable $e) {
            Log::error('Migration failed: ' . $e->getMessage());
            $this->dispatchBrowserEvent('notify', ['type' => 'error', 'message' => 'Migrations failed: '.$e->getMessage()]);
        } finally {
            $this->migrating = false;
        }
    }

    public function render()
    {
        return view('livewire.setup-page')->layout('layouts.setup');
    }
}
