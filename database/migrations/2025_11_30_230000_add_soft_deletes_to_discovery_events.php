<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add deleted_at only when it's actually missing — protects against
        // running this migration in environments where the column already exists
        if (! Schema::hasColumn('discovery_events', 'deleted_at')) {
            Schema::table('discovery_events', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('discovery_events', 'deleted_at')) {
            Schema::table('discovery_events', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
