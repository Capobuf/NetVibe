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
        foreach (['subnets', 'vlans', 'mikrotik_sources'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('excluded_at')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['subnets', 'vlans', 'mikrotik_sources'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('excluded_at');
            });
        }
    }
};
