<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Insert a default discovery settings row for the Spatie settings repository.
        // The payload is a JSON object mapping the setting properties to their defaults.
        DB::table('settings')->updateOrInsert(
            ['group' => 'discovery', 'name' => 'default'],
            ['payload' => json_encode([
                'default_scan_interval_minutes' => 60,
                'icmp_timeout_ms' => 200,
                'icmp_retries' => 1,
            ]), 'locked' => false, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down()
    {
        DB::table('settings')->where(['group' => 'discovery', 'name' => 'default'])->delete();
    }
};
