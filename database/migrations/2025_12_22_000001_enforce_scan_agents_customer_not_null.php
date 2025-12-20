<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scan_agents')) {
            return;
        }

        // Fast-fail: NetVibe no longer supports "global" agents.
        $nullCount = (int) DB::table('scan_agents')->whereNull('customer_id')->count();
        if ($nullCount > 0) {
            throw new RuntimeException('Found scan_agents with NULL customer_id. Assign every ScanAgent to a Customer before migrating.');
        }

        // Adjust FK semantics: customer_id is required and should cascade on customer deletion.
        Schema::table('scan_agents', function (Blueprint $table) {
            // Best-effort drop of the previous constraint (might not exist in some environments)
            try {
                $table->dropForeign(['customer_id']);
            } catch (Throwable $e) {
                // ignore
            }
        });

        // MariaDB/MySQL: enforce NOT NULL without requiring doctrine/dbal.
        DB::statement('ALTER TABLE scan_agents MODIFY customer_id BIGINT UNSIGNED NOT NULL');

        Schema::table('scan_agents', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scan_agents')) {
            return;
        }

        Schema::table('scan_agents', function (Blueprint $table) {
            try {
                $table->dropForeign(['customer_id']);
            } catch (Throwable $e) {
                // ignore
            }
        });

        DB::statement('ALTER TABLE scan_agents MODIFY customer_id BIGINT UNSIGNED NULL');

        Schema::table('scan_agents', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
        });
    }
};
