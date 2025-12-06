<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;

$rows = DB::select('select * from migrations order by id desc limit 50');
foreach ($rows as $r) {
    echo ($r->id ?? '') . "\t" . ($r->migration ?? '') . "\t" . ($r->batch ?? '') . "\n";
}
if (empty($rows)) {
    echo "<no migrations>\n";
}
