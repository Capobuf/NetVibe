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
                if (! Schema::hasColumn('devices', 'auth_status')) {
                    $table->string('auth_status')->default('unknown')->after('auth_extra');
                }

                if (! Schema::hasColumn('devices', 'last_seen_at')) {
                    $table->timestamp('last_seen_at')->nullable()->after('auth_status');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('devices')) {
            Schema::table('devices', function (Blueprint $table) {
                if (Schema::hasColumn('devices', 'last_seen_at')) {
                    $table->dropColumn('last_seen_at');
                }

                if (Schema::hasColumn('devices', 'auth_status')) {
                    $table->dropColumn('auth_status');
                }
            });
        }
    }
};
