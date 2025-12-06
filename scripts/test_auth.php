<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Models\User;
use Illuminate\Support\Facades\Hash;
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$user = User::where('email','info@bydot.it')->first();
if (! $user) {
    echo "MISSING\n"; exit(1);
}
echo Hash::check('Dotroot34!', $user->password) ? "AUTH_OK\n" : "AUTH_FAIL\n";
