<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// Force test DB configuration to the known test instance so tests always run
// against the `netvibe-db` test database. This avoids needing to export env vars
// externally in CI/local environment. Be careful: this intentionally overrides
// environment and will make tests attempt to connect to the named host.
putenv('DB_CONNECTION=mysql');
putenv('DB_DATABASE=netvibe-test');
putenv('DB_HOST=netvibe-db');
putenv('DB_USERNAME=root');
putenv('DB_PASSWORD=secret');

// Also populate superglobals so code using env('...') or $_ENV/$_SERVER sees them
$_ENV['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = 'netvibe-test';
$_ENV['DB_HOST'] = 'netvibe-db';
$_ENV['DB_USERNAME'] = 'root';
$_ENV['DB_PASSWORD'] = 'secret';

$_SERVER['DB_CONNECTION'] = 'mysql';
$_SERVER['DB_DATABASE'] = 'netvibe-test';
$_SERVER['DB_HOST'] = 'netvibe-db';
$_SERVER['DB_USERNAME'] = 'root';
$_SERVER['DB_PASSWORD'] = 'secret';

uses(Tests\TestCase::class)
    ->beforeEach(function () {
        // Chiama la funzione di setup del pannello prima di ogni test di Feature/Unit
        $this->bootFilament();
    })
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
// End of Pest helpers

// Common test helper: create an admin user for test suites
// Guard the helper against multiple declarations so test files that accidentally
// declare the same helper don't cause a fatal error when the whole suite runs.
if (! function_exists('createAdminUser')) {
    function createAdminUser()
    {
    // Create and return a basic user for use as an authenticated admin in tests.
    // If the project uses roles, this helper can be extended to give the user
    // an admin role. For now return a simple factory-created user.
    return \App\Models\User::factory()->create();
    }
}

// Provide a minimal Pest\Livewire\livewire() helper when the Pest livewire plugin is not installed.
// Keep tests portable across environments by loading a small shim file which declares the
// Pest\Livewire namespace and the livewire() helper.
require_once __DIR__ . '/Helpers/PestLivewireShim.php';
