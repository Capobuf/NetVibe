<?php
if ($argc < 3) {
    echo "Usage: php reset_password.php user@example.com new_password\n";
    exit(1);
}

$email = $argv[1];
$newPassword = $argv[2];

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$user = User::where('email', $email)->first();
if (! $user) {
    echo "User not found: $email\n";
    exit(2);
}

$user->password = Hash::make($newPassword);
$user->save();

echo "Password for {$email} reset to provided value.\n";
