<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Null on both: the model inherits its type, the type decides from the model's network ports.
        Schema::table('model_types', function (Blueprint $table) {
            $table->boolean('show_in_topology')->nullable();
        });
        Schema::table('device_models', function (Blueprint $table) {
            $table->boolean('show_in_topology')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_models', fn (Blueprint $table) => $table->dropColumn('show_in_topology'));
        Schema::table('model_types', fn (Blueprint $table) => $table->dropColumn('show_in_topology'));
    }
};
