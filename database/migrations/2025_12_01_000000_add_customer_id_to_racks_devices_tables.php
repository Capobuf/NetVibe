<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add customer_id to devices table (nullable) if missing
        if (Schema::hasTable('devices') && ! Schema::hasColumn('devices', 'customer_id')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null')->after('id');
            });
        }

        // Add customer_id to racks table (nullable) if missing
        if (Schema::hasTable('racks') && ! Schema::hasColumn('racks', 'customer_id')) {
            Schema::table('racks', function (Blueprint $table) {
                $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null')->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'customer_id')) {
            Schema::table('devices', function (Blueprint $table) {
                try {
                    $table->dropForeign(['customer_id']);
                } catch (\Throwable $e) {
                    // ignore
                }

                $table->dropColumn('customer_id');
            });
        }

        if (Schema::hasTable('racks') && Schema::hasColumn('racks', 'customer_id')) {
            Schema::table('racks', function (Blueprint $table) {
                try {
                    $table->dropForeign(['customer_id']);
                } catch (\Throwable $e) {
                    // ignore
                }

                $table->dropColumn('customer_id');
            });
        }
    }
};
