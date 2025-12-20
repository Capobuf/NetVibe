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
        // 1) Add nullable column first so we can backfill existing rows.
        Schema::table('discovery_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('discovery_events', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('id')->index();
            }
        });

        // 2) Backfill from scan_agents where possible.
        DB::table('discovery_events')
            ->whereNull('customer_id')
            ->whereNotNull('scan_agent_id')
            ->update([
                'customer_id' => DB::raw('(SELECT sa.customer_id FROM scan_agents sa WHERE sa.id = discovery_events.scan_agent_id)')
            ]);

        // 3) Fast-fail if any rows are still missing customer_id.
        $missing = (int) DB::table('discovery_events')->whereNull('customer_id')->count();
        if ($missing > 0) {
            throw new RuntimeException(
                "Migration aborted: discovery_events contains {$missing} rows without customer_id. " .
                'Ensure scan_agent_id is present and scan_agents.customer_id is set, then retry.'
            );
        }

        // 4) Enforce NOT NULL and add FK (MariaDB/MySQL).
        DB::statement('ALTER TABLE discovery_events MODIFY customer_id BIGINT UNSIGNED NOT NULL');

        try {
            Schema::table('discovery_events', function (Blueprint $table): void {
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            });
        } catch (Throwable $e) {
            // Fast-fail was already enforced above. If the FK already exists (or the
            // engine differs), we tolerate it to keep the migration idempotent.
        }
    }

    public function down(): void
    {
        Schema::table('discovery_events', function (Blueprint $table): void {
            if (Schema::hasColumn('discovery_events', 'customer_id')) {
                try {
                    $table->dropForeign(['customer_id']);
                } catch (Throwable $e) {
                    // ignore
                }
                $table->dropColumn('customer_id');
            }
        });
    }
};
