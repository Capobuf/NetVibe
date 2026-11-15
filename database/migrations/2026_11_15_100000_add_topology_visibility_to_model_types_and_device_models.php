<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_types', function (Blueprint $table) {
            $table->boolean('show_in_topology')->default(true);
        });
        Schema::table('device_models', function (Blueprint $table) {
            // Null inherits the model type; true/false override it.
            $table->boolean('show_in_topology')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_models', fn (Blueprint $table) => $table->dropColumn('show_in_topology'));
        Schema::table('model_types', fn (Blueprint $table) => $table->dropColumn('show_in_topology'));
    }
};
