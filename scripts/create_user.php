<?php
// Utility: create a user with a known password (used for dev/testing instances)
require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Hash;
use App\Models\User;

$email = getenv('EMAIL') ?: 'info@bydot.it';
$password = getenv('PASSWORD') ?: 'Dotroot34!';

// Boot Laravel application to get DB / Eloquent working
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (User::where('email', $email)->exists()) {
    echo "ALREADY_EXISTS\n";
    exit(0);
}

$user = User::create([
    'name' => 'Bydot',
    'email' => $email,
    'password' => Hash::make($password),
]);

if ($user) {
    echo "CREATED\n";
    exit(0);
}

echo "FAILED\n";
