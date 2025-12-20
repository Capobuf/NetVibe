<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subnets')) {
            Schema::table('subnets', function (Blueprint $table) {
                if (! Schema::hasColumn('subnets', 'last_discovery')) {
                    // Add column safely: place after scan_agent_id if present, otherwise append to table.
                    if (Schema::hasColumn('subnets', 'scan_agent_id')) {
                        $table->timestamp('last_discovery')->nullable()->after('scan_agent_id');
                    } else {
                        $table->timestamp('last_discovery')->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subnets') && Schema::hasColumn('subnets', 'last_discovery')) {
            Schema::table('subnets', function (Blueprint $table) {
                $table->dropColumn('last_discovery');
            });
        }
    }
};
