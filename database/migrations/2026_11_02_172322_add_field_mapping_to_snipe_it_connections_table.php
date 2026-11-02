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
        Schema::table('snipe_it_connections', function (Blueprint $table) {
            $table->json('field_mapping')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('snipe_it_connections', function (Blueprint $table) {
            $table->dropColumn('field_mapping');
        });
    }
};
