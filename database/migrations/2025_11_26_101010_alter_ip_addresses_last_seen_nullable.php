<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convert any legacy placeholder timestamps to NULL
        DB::table('ip_addresses')
            ->where('last_seen', '1970-01-01 00:00:01')
            ->update(['last_seen' => null]);

        // Set column default to NULL (MariaDB / MySQL syntax)
        DB::statement("ALTER TABLE `ip_addresses` MODIFY `last_seen` TIMESTAMP NULL DEFAULT NULL;");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert existing NULLs to the legacy placeholder
        DB::table('ip_addresses')
            ->whereNull('last_seen')
            ->update(['last_seen' => '1970-01-01 00:00:01']);

        DB::statement("ALTER TABLE `ip_addresses` MODIFY `last_seen` TIMESTAMP NULL DEFAULT '1970-01-01 00:00:01';");
    }
};
