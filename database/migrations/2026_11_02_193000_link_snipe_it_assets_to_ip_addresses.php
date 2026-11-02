<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snipe_it_assets', function (Blueprint $table) {
            $table->foreignId('ip_address_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('snipe_it_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ip_address_id');
        });
    }
};
