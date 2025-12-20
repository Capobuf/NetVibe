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

        // Attempt to add the missing columns in small guarded blocks. Some test/CI
        // environments might trigger duplicate-add attempts due to parallel runs
        // or older migrations already having applied these columns. We guard
        // against duplicate column errors to make migrations idempotent.

        try {
            if (! Schema::hasColumn('scan_agents', 'active')) {
                Schema::table('scan_agents', function (Blueprint $table) {
                    $table->boolean('active')->default(true)->after('description');
                });
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Ignore duplicate column race
            if (stripos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }

        try {
            if (! Schema::hasColumn('scan_agents', 'ip_address')) {
                Schema::table('scan_agents', function (Blueprint $table) {
                    $table->string('ip_address')->nullable()->after('active');
                });
            }
        } catch (\Illuminate\Database\QueryException $e) {
            if (stripos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }

        try {
            if (! Schema::hasColumn('scan_agents', 'last_seen')) {
                // Keep this nullable to avoid forcing values during seed in older DBs
                Schema::table('scan_agents', function (Blueprint $table) {
                    $table->timestamp('last_seen')->nullable()->after('ip_address');
                    $table->index('last_seen');
                });
            }
        } catch (\Illuminate\Database\QueryException $e) {
            if (stripos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
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
