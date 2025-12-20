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
                if (! Schema::hasColumn('devices', 'credential_profile_id')) {
                    $table->foreignId('credential_profile_id')->nullable()->constrained('credential_profiles')->nullOnDelete()->after('device_model_id');
                }

                if (! Schema::hasColumn('devices', 'auth_username')) {
                    $table->string('auth_username')->nullable()->after('credential_profile_id');
                }

                if (! Schema::hasColumn('devices', 'auth_password')) {
                    // store as TEXT to support long encrypted payloads
                    $table->text('auth_password')->nullable()->after('auth_username');
                }

                if (! Schema::hasColumn('devices', 'auth_extra')) {
                    $table->text('auth_extra')->nullable()->after('auth_password');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('devices')) {
            Schema::table('devices', function (Blueprint $table) {
                if (Schema::hasColumn('devices', 'auth_extra')) {
                    $table->dropColumn('auth_extra');
                }

                if (Schema::hasColumn('devices', 'auth_password')) {
                    $table->dropColumn('auth_password');
                }

                if (Schema::hasColumn('devices', 'auth_username')) {
                    $table->dropColumn('auth_username');
                }

                if (Schema::hasColumn('devices', 'credential_profile_id')) {
                    $table->dropForeign(['credential_profile_id']);
                    $table->dropColumn('credential_profile_id');
                }
            });
        }
    }
};
