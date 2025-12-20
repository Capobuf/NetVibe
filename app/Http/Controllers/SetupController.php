<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\SystemStateSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Throwable;

class SetupController extends Controller
{
    /**
     * Step 1: Pre-flight system checks.
     */
    public function index()
    {
        // DB connectivity check
        try {
            DB::connection()->getPdo();
            $dbPassed = true;
            $dbMessage = 'Connected';
        } catch (Throwable $e) {
            $dbPassed = false;
            $dbMessage = $e->getMessage();
        }

        // Redis connectivity check (only if effectively used)
        $redisRequired = in_array(config('session.driver'), ['redis'], true)
            || config('queue.default') === 'redis'
            || config('cache.default') === 'redis';

        if ($redisRequired) {
            try {
                Redis::connection()->ping();
                $redisPassed = true;
                $redisMessage = 'Connected';
            } catch (Throwable $e) {
                $redisPassed = false;
                $redisMessage = $e->getMessage();
            }
        } else {
            $redisPassed = true;
            $redisMessage = 'Not required (file/array drivers)';
        }

        $checks = [
            'php_version' => [
                'label' => 'PHP Version >= 8.2',
                'passed' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'message' => 'Current: ' . PHP_VERSION,
            ],
            'extension_openssl' => [
                'label' => 'OpenSSL Extension',
                'passed' => extension_loaded('openssl'),
                'message' => extension_loaded('openssl') ? 'Installed' : 'Missing',
            ],
            'extension_pdo' => [
                'label' => 'PDO Extension',
                'passed' => extension_loaded('pdo'),
                'message' => extension_loaded('pdo') ? 'Installed' : 'Missing',
            ],
            'extension_mbstring' => [
                'label' => 'Mbstring Extension',
                'passed' => extension_loaded('mbstring'),
                'message' => extension_loaded('mbstring') ? 'Installed' : 'Missing',
            ],
            'extension_tokenizer' => [
                'label' => 'Tokenizer Extension',
                'passed' => extension_loaded('tokenizer'),
                'message' => extension_loaded('tokenizer') ? 'Installed' : 'Missing',
            ],
            'extension_xml' => [
                'label' => 'XML Extension',
                'passed' => extension_loaded('xml'),
                'message' => extension_loaded('xml') ? 'Installed' : 'Missing',
            ],
            'writable_env' => [
                'label' => '.env File Writable',
                'passed' => is_writable(base_path('.env')),
                'message' => is_writable(base_path('.env')) ? 'Writable' : 'Not writable',
            ],
            'writable_storage' => [
                'label' => 'storage/ Directory Writable',
                'passed' => is_writable(storage_path()),
                'message' => is_writable(storage_path()) ? 'Writable' : 'Not writable',
            ],
            'writable_bootstrap_cache' => [
                'label' => 'bootstrap/cache/ Directory Writable',
                'passed' => is_writable(base_path('bootstrap/cache')),
                'message' => is_writable(base_path('bootstrap/cache')) ? 'Writable' : 'Not writable',
            ],
            'db_connection' => [
                'label' => 'Database Connection',
                'passed' => $dbPassed,
                'message' => $dbMessage,
            ],
            'redis_connection' => [
                'label' => 'Redis Connection',
                'passed' => $redisPassed,
                'message' => $redisMessage,
            ],
        ];

        $allPassed = collect($checks)->every(fn($check) => $check['passed']);

        return view('setup.index', compact('checks', 'allPassed'));
    }

    /**
     * Step 2: Show migration page.
     */
    public function showMigrate()
    {
        return view('setup.migrate');
    }

    /**
     * Step 2: Run migrations.
     */
    public function runMigrate()
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();

            // Segna l'istanza come "installed" dal punto di vista delle migrazioni
            if (class_exists(SystemStateSettings::class)) {
                try {
                    /** @var \App\Settings\SystemStateSettings $state */
                    $state = app(SystemStateSettings::class);
                    $state->installed = true;

                    // Non toccare configured/last_* in questo step: verranno aggiornati
                    // quando il wizard sarà completato (storeApp, heartbeat, health page).
                    $state->save();
                } catch (MissingSettings $e) {
                    // Se esistono già record parziali in `settings` per il group system_state`
                    // (ad esempio da versioni precedenti), forziamo un payload completo
                    // coerente con la definizione attuale della classe Settings.
                    DB::table('settings')->updateOrInsert(
                        ['group' => SystemStateSettings::group(), 'name' => 'installed'],
                        ['payload' => json_encode(true), 'locked' => false]
                    );

                    DB::table('settings')->updateOrInsert(
                        ['group' => SystemStateSettings::group(), 'name' => 'configured'],
                        ['payload' => json_encode(false), 'locked' => false]
                    );

                    foreach (['last_scheduler_run_at', 'last_worker_heartbeat_at', 'last_health_check_at'] as $name) {
                        DB::table('settings')->updateOrInsert(
                            ['group' => SystemStateSettings::group(), 'name' => $name],
                            ['payload' => json_encode(null), 'locked' => false]
                        );
                    }
                }
            }

            return redirect()
                ->route('setup.user')
                ->with('success', 'Database migrated successfully!');
        } catch (Throwable $e) {
            return back()
                ->withErrors(['migration' => 'Migration failed: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Step 3: Show user creation form.
     */
    public function showUser()
    {
        // Check if already has users
        if (Schema::hasTable('users') && User::exists()) {
            return redirect('/')->with('info', 'System already has users configured.');
        }

        return view('setup.user');
    }

    /**
     * Step 3: Create first admin user.
     */
    public function storeUser(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
            ]);

            // Auto-login the newly created user
            auth()->login($user);
            
            // Dopo la creazione dell'admin si prosegue con la creazione del primo customer/location
            return redirect()
                ->route('setup.customer')
                ->with('success', 'Admin account created. Let\'s configure your first customer.');
        } catch (Throwable $e) {
            return back()
                ->withErrors(['user' => 'User creation failed: ' . $e->getMessage()])
                ->withInput($request->except('password', 'password_confirmation'));
        }
    }

    /**
     * Step 4: Show first Customer & Location form.
     */
    public function showCustomer()
    {
        // Se esiste già almeno un customer, salta questo step
        if (Customer::query()->exists()) {
            return redirect()->route('setup.app');
        }

        return view('setup.customer');
    }

    /**
     * Step 4: Store first Customer & default Location.
     */
    public function storeCustomer(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $customer = Customer::create([
                'name' => $validated['customer_name'],
            ]);

            Location::create([
                'customer_id' => $customer->id,
                'name' => $validated['location_name'] ?: 'Default',
                'address' => $validated['location_address'] ?? null,
            ]);

            return redirect()
                ->route('setup.app')
                ->with('success', 'Customer and default location created.');
        } catch (Throwable $e) {
            return back()
                ->withErrors(['customer' => 'Customer creation failed: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Step 5: App URL & basic app config.
     */
    public function showApp()
    {
        // In caso di re-accesso, se già configurato si va alla home
        if (class_exists(SystemStateSettings::class)) {
            /** @var \App\Settings\SystemStateSettings $state */
            $state = app(SystemStateSettings::class);
            if ($state->configured) {
                return redirect('/');
            }
        }

        $general = app(GeneralSettings::class);

        $appUrl = old('app_url', $general->app_url ?: config('app.url'));
        $locale = old('locale', config('app.locale', 'en'));
        $queueDriver = config('queue.default');

        return view('setup.app', compact('appUrl', 'locale', 'queueDriver'));
    }

    /**
     * Step 5: Store app config (app_url, locale) and finish wizard.
     */
    public function storeApp(Request $request)
    {
        $validated = $request->validate([
            'app_url' => ['required', 'string', 'max:255'],
            'locale' => ['required', 'in:en,it'],
        ]);

        try {
            // Aggiorna GeneralSettings (app_url)
            /** @var \App\Settings\GeneralSettings $general */
            $general = app(GeneralSettings::class);
            $general->app_url = $validated['app_url'];
            try {
                $general->save();
            } catch (MissingSettings $e) {
                // Se il gruppo "general" è parzialmente popolato (es. nuove proprietà
                // aggiunte rispetto a install precedenti), forziamo un payload completo
                // coerente con la definizione attuale della classe Settings.
                $group = GeneralSettings::group();

                $payloads = [
                    'site_name' => $general->site_name,
                    'site_color' => $general->site_color,
                    'app_url' => $general->app_url,
                    'force_https' => $general->force_https,
                    'agent_alert_level' => $general->agent_alert_level,
                    'time_display_format' => $general->time_display_format,
                ];

                foreach ($payloads as $name => $value) {
                    DB::table('settings')->updateOrInsert(
                        ['group' => $group, 'name' => $name],
                        ['payload' => json_encode($value), 'locked' => false]
                    );
                }
            }

            // Aggiorna locale in .env se possibile
            $envPath = base_path('.env');
            if (is_writable($envPath)) {
                $env = file_get_contents($envPath) ?: '';
                if (str_contains($env, 'APP_LOCALE=')) {
                    $env = preg_replace('/^APP_LOCALE=.*$/m', 'APP_LOCALE='.$validated['locale'], $env);
                } else {
                    $env .= PHP_EOL.'APP_LOCALE='.$validated['locale'];
                }
                file_put_contents($envPath, $env);
            }

            // Segna l'istanza come configurata
            if (class_exists(SystemStateSettings::class)) {
                /** @var \App\Settings\SystemStateSettings $state */
                $state = app(SystemStateSettings::class);
                $state->configured = true;
                $state->save();
            }

            return redirect('/')
                ->with('success', 'Setup completed! Welcome to NetVibe.');
        } catch (Throwable $e) {
            return back()
                ->withErrors(['app' => 'App configuration failed: ' . $e->getMessage()])
                ->withInput();
        }
    }
}
