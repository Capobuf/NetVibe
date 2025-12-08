<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('devices') && ! Schema::hasColumn('devices', 'scan_agent_id')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->foreignId('scan_agent_id')->nullable()->constrained('scan_agents')->nullOnDelete()->after('customer_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'scan_agent_id')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropForeign(['scan_agent_id']);
                $table->dropColumn('scan_agent_id');
            });
        }
    }
};
