<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_logs')) {
            return;
        }

        Schema::table('agent_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('agent_logs', 'tenant_id')) {
                $table->foreignId('tenant_id')
                    ->nullable()
                    ->after('scan_agent_id')
                    ->constrained('customers')
                    ->nullOnDelete();
            }
        });

        // Best-effort backfill: tenant_id = ScanAgent.customer_id
        try {
            DB::statement('
                UPDATE agent_logs al
                JOIN scan_agents sa ON al.scan_agent_id = sa.id
                SET al.tenant_id = sa.customer_id
                WHERE al.tenant_id IS NULL
            ');
        } catch (\Throwable $e) {
            // Early dev environments may not have consistent data; ignore failures here.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_logs')) {
            return;
        }

        Schema::table('agent_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('agent_logs', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });
    }
};

