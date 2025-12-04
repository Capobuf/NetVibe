<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Throwable;

class SetupController extends Controller
{
    /**
     * Step 1: Pre-flight system checks.
     */
    public function index()
    {
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

            return redirect('/')
                ->with('success', 'Setup completed! Welcome to NetVibe.');
        } catch (Throwable $e) {
            return back()
                ->withErrors(['user' => 'User creation failed: ' . $e->getMessage()])
                ->withInput($request->except('password', 'password_confirmation'));
        }
    }
}
