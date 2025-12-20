<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$id = (int)($argv[1] ?? 1);
$email = $argv[2] ?? 'admin@modernipam.local';

$user = \App\Models\User::where('email', $email)->first();
if (! $user) {
    echo json_encode(['error' => 'user not found', 'email' => $email]) . PHP_EOL;
    exit(0);
}

// Log in the user for this request
\Illuminate\Support\Facades\Auth::login($user);

// Build request and dispatch internally
$request = \Illuminate\Http\Request::create("/admin/ipam/subnets/{$id}", 'GET');
$response = app()->handle($request);

// Print HTTP status and first 8000 chars of the body
echo json_encode([
    'status' => $response->getStatusCode(),
    'length' => strlen($response->getContent()),
    'snippet' => substr($response->getContent(), 0, 8000),
]) . PHP_EOL;
