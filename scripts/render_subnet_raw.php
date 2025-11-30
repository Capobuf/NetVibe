<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$id = (int)($argv[1] ?? 1);
$email = $argv[2] ?? 'admin@modernipam.local';

$user = \App\Models\User::where('email', $email)->first();
if (! $user) {
    echo "User not found: {$email}\n";
    exit(1);
}

\Illuminate\Support\Facades\Auth::login($user);
$request = \Illuminate\Http\Request::create("/admin/ipam/subnets/{$id}", 'GET');
$response = app()->handle($request);

// Print the raw HTML content
echo $response->getContent();
