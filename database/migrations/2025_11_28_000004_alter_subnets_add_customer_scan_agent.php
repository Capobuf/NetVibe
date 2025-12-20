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
                if (! Schema::hasColumn('subnets', 'customer_id')) {
                    $table->unsignedBigInteger('customer_id')->nullable()->after('mask');
                    $table->index('customer_id');
                    // keep deletion of customer from deleting subnets — set null
                    $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
                }

                if (! Schema::hasColumn('subnets', 'scan_agent_id')) {
                    $table->unsignedBigInteger('scan_agent_id')->nullable()->after('customer_id');
                    $table->index('scan_agent_id');
                    $table->foreign('scan_agent_id')->references('id')->on('scan_agents')->onDelete('set null');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subnets')) {
            Schema::table('subnets', function (Blueprint $table) {
                if (Schema::hasColumn('subnets', 'scan_agent_id')) {
                    $table->dropForeign(['scan_agent_id']);
                    $table->dropIndex(['scan_agent_id']);
                    $table->dropColumn('scan_agent_id');
                }

                if (Schema::hasColumn('subnets', 'customer_id')) {
                    $table->dropForeign(['customer_id']);
                    $table->dropIndex(['customer_id']);
                    $table->dropColumn('customer_id');
                }
            });
        }
    }
};
