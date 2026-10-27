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
        Schema::create('excluded_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subnet_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('start_address');
            $table->unsignedInteger('end_address');
            $table->unique(['subnet_id', 'start_address']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('excluded_ranges');
    }
};
