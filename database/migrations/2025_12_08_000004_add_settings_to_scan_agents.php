<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scan_agents')) {
            Schema::table('scan_agents', function (Blueprint $table) {
                if (! Schema::hasColumn('scan_agents', 'settings')) {
                    $table->json('settings')->nullable()->after('description');
                }

                if (! Schema::hasColumn('scan_agents', 'polling_interval_minutes')) {
                    $table->integer('polling_interval_minutes')->nullable()->after('settings');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scan_agents')) {
            Schema::table('scan_agents', function (Blueprint $table) {
                if (Schema::hasColumn('scan_agents', 'polling_interval_minutes')) {
                    $table->dropColumn('polling_interval_minutes');
                }

                if (Schema::hasColumn('scan_agents', 'settings')) {
                    $table->dropColumn('settings');
                }
            });
        }
    }
};
