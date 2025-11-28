<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ip_addresses') && ! Schema::hasColumn('ip_addresses', 'last_seen')) {
            Schema::table('ip_addresses', function (Blueprint $table) {
                // Add last_seen as nullable so legacy placeholder values and defaults are allowed
                $table->timestamp('last_seen')->nullable();
                $table->index('last_seen');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ip_addresses') && Schema::hasColumn('ip_addresses', 'last_seen')) {
            Schema::table('ip_addresses', function (Blueprint $table) {
                $table->dropIndex(['last_seen']);
                $table->dropColumn('last_seen');
            });
        }
    }
};
