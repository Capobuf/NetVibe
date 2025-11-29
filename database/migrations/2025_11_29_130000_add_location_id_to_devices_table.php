<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('devices') && ! Schema::hasColumn('devices', 'location_id')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('restrict')->after('rack_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'location_id')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('location_id');
            });
        }
    }
};
