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
        if (! Schema::hasTable('agent_jobs')) {
            return;
        }

        Schema::table('agent_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('agent_jobs', 'tenant_id')) {
                $table->foreignId('tenant_id')
                    ->nullable()
                    ->after('scan_agent_id')
                    ->constrained('customers')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('agent_jobs', 'scope')) {
                $table->string('scope', 32)
                    ->nullable()
                    ->after('type');
            }

            if (! Schema::hasColumn('agent_jobs', 'result_metadata')) {
                $table->json('result_metadata')
                    ->nullable()
                    ->after('result');
            }

            if (! Schema::hasColumn('agent_jobs', 'error_message')) {
                $table->string('error_message')
                    ->nullable()
                    ->after('result_metadata');
            }
        });

        // Best-effort backfill of tenant_id from related device.customer_id
        try {
            DB::statement('
                UPDATE agent_jobs aj
                JOIN devices d ON aj.device_id = d.id
                SET aj.tenant_id = d.customer_id
                WHERE aj.tenant_id IS NULL
            ');
        } catch (\Throwable $e) {
            // In early development environments this backfill is best-effort only.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_jobs')) {
            return;
        }

        Schema::table('agent_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('agent_jobs', 'error_message')) {
                $table->dropColumn('error_message');
            }

            if (Schema::hasColumn('agent_jobs', 'result_metadata')) {
                $table->dropColumn('result_metadata');
            }

            if (Schema::hasColumn('agent_jobs', 'scope')) {
                $table->dropColumn('scope');
            }

            if (Schema::hasColumn('agent_jobs', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });
    }
};

