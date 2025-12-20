<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ports')) {
            Schema::table('ports', function (Blueprint $table) {
                if (! Schema::hasColumn('ports', 'last_seen')) {
                    $table->timestamp('last_seen')->nullable()->after('mac_address');
                }

                if (! Schema::hasColumn('ports', 'discovered_by_scan_agent_id')) {
                    $table->foreignId('discovered_by_scan_agent_id')->nullable()->constrained('scan_agents')->nullOnDelete()->after('last_seen');
                }

                if (! Schema::hasColumn('ports', 'exclude_from_discovery')) {
                    $table->boolean('exclude_from_discovery')->default(false)->after('status');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ports')) {
            Schema::table('ports', function (Blueprint $table) {
                if (Schema::hasColumn('ports', 'discovered_by_scan_agent_id')) {
                    $table->dropForeign(['discovered_by_scan_agent_id']);
                    $table->dropColumn('discovered_by_scan_agent_id');
                }

                if (Schema::hasColumn('ports', 'last_seen')) {
                    $table->dropColumn('last_seen');
                }

                if (Schema::hasColumn('ports', 'exclude_from_discovery')) {
                    $table->dropColumn('exclude_from_discovery');
                }
            });
        }
    }
};
