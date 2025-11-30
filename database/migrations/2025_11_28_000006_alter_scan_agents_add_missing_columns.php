<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scan_agents')) {
            return;
        }

        Schema::table('scan_agents', function (Blueprint $table) {
            if (! Schema::hasColumn('scan_agents', 'active')) {
                $table->boolean('active')->default(true)->after('description');
            }

            if (! Schema::hasColumn('scan_agents', 'ip_address')) {
                $table->string('ip_address')->nullable()->after('active');
            }

            if (! Schema::hasColumn('scan_agents', 'last_seen')) {
                // Keep this nullable to avoid forcing values during seed in older DBs
                $table->timestamp('last_seen')->nullable()->after('ip_address');
                $table->index('last_seen');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scan_agents')) {
            return;
        }

        Schema::table('scan_agents', function (Blueprint $table) {
            if (Schema::hasColumn('scan_agents', 'last_seen')) {
                $table->dropIndex(['last_seen']);
                $table->dropColumn('last_seen');
            }

            if (Schema::hasColumn('scan_agents', 'ip_address')) {
                $table->dropColumn('ip_address');
            }

            if (Schema::hasColumn('scan_agents', 'active')) {
                $table->dropColumn('active');
            }
        });
    }
};
