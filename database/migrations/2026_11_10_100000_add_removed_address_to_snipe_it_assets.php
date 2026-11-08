<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snipe_it_assets', function (Blueprint $table) {
            // The asset address whose documentation the technician removed: Snipe-IT must not recreate it.
            $table->unsignedInteger('removed_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('snipe_it_assets', fn (Blueprint $table) => $table->dropColumn('removed_address'));
    }
};
