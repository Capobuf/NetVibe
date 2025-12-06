<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::select('select id, email, name from users');

foreach ($rows as $r) {
    echo ($r->id ?? '') . "\t" . ($r->email ?? '') . "\t" . ($r->name ?? '') . "\n";
}

if (empty($rows)) {
    echo "<no users>\n";
}
