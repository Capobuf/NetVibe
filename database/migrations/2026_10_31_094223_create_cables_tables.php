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
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('cabling_revision')->default(0);
        });
        Schema::create('cables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->char('color', 7);
            $table->text('purpose')->nullable();
            $table->timestamps();
        });
        Schema::create('cable_ends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cable_id')->constrained()->cascadeOnDelete();
            $table->enum('side', ['a', 'b']);
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->char('connector_id', 32);
            $table->unique(['cable_id', 'side']);
            $table->unique(['device_id', 'connector_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cable_ends');
        Schema::dropIfExists('cables');
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('cabling_revision'));
    }
};
