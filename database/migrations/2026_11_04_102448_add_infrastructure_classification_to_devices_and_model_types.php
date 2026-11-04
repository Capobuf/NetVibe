<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_types', function (Blueprint $table) {
            $table->boolean('is_infrastructure')->default(false);
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('infrastructure_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('infrastructure_override'));
        Schema::table('model_types', fn (Blueprint $table) => $table->dropColumn('is_infrastructure'));
    }
};
