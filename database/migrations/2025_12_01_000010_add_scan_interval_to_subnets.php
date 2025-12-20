<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('subnets', function (Blueprint $table): void {
            if (! Schema::hasColumn('subnets', 'scan_interval')) {
                // Add column after discover_subnet if that column exists, otherwise append at end
                if (Schema::hasColumn('subnets', 'discover_subnet')) {
                    $table->unsignedInteger('scan_interval')->nullable()->after('discover_subnet');
                } else {
                    $table->unsignedInteger('scan_interval')->nullable();
                }
            }
        });
    }

    public function down()
    {
        Schema::table('subnets', function (Blueprint $table): void {
            if (Schema::hasColumn('subnets', 'scan_interval')) {
                $table->dropColumn('scan_interval');
            }
        });
    }
};
