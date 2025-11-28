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
                    $table->timestamp('last_discovery')->nullable()->after('scan_agent_id');
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
