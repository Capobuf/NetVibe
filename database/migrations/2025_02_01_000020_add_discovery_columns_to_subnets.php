<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('subnets', function (Blueprint $table): void {
            if (! Schema::hasColumn('subnets', 'discover_subnet')) {
                $table->boolean('discover_subnet')->default(true)->after('cidr');
            }

            if (! Schema::hasColumn('subnets', 'scan_interval')) {
                $table->integer('scan_interval')->nullable()->after('discover_subnet');
            }

            if (! Schema::hasColumn('subnets', 'scan_agent_id')) {
                if (Schema::hasTable('scan_agents')) {
                    $table->foreignId('scan_agent_id')->nullable()->constrained('scan_agents')->nullOnDelete();
                } else {
                    // create the column without FK; a later migration will add the constraint
                    $table->unsignedBigInteger('scan_agent_id')->nullable();
                }
            }

            if (! Schema::hasColumn('subnets', 'last_discovery')) {
                $table->timestamp('last_discovery')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('subnets', function (Blueprint $table): void {
            if (Schema::hasColumn('subnets', 'last_discovery')) {
                $table->dropColumn('last_discovery');
            }

            if (Schema::hasColumn('subnets', 'scan_agent_id')) {
                // Drop constrained FK first, then the column
                $table->dropForeign(['scan_agent_id']);
                $table->dropColumn('scan_agent_id');
            }

            if (Schema::hasColumn('subnets', 'scan_interval')) {
                $table->dropColumn('scan_interval');
            }

            if (Schema::hasColumn('subnets', 'discover_subnet')) {
                $table->dropColumn('discover_subnet');
            }
        });
    }
};
