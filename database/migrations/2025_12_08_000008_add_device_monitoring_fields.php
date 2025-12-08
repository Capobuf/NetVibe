<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('devices')) {
            Schema::table('devices', function (Blueprint $table) {
                if (! Schema::hasColumn('devices', 'is_monitored')) {
                    $table->boolean('is_monitored')->default(true)->after('ip_addr');
                }

                if (! Schema::hasColumn('devices', 'auth_driver')) {
                    $table->string('auth_driver')->default('mikrotik_rest')->after('is_monitored');
                }

                if (! Schema::hasColumn('devices', 'management_ip')) {
                    $table->string('management_ip')->nullable()->after('auth_driver');
                }

                if (! Schema::hasColumn('devices', 'brand')) {
                    $table->string('brand')->nullable()->default('MikroTik')->after('auth_driver');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('devices')) {
            Schema::table('devices', function (Blueprint $table) {
                if (Schema::hasColumn('devices', 'is_monitored')) {
                    $table->dropColumn('is_monitored');
                }

                if (Schema::hasColumn('devices', 'auth_driver')) {
                    $table->dropColumn('auth_driver');
                }

                if (Schema::hasColumn('devices', 'management_ip')) {
                    $table->dropColumn('management_ip');
                }

                if (Schema::hasColumn('devices', 'brand')) {
                    $table->dropColumn('brand');
                }
            });
        }
    }
};
