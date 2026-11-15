<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // Same code as racks.floor (PT, P1, P-1…), so racks and floors stay comparable.
            $table->string('code', 12);
            // The floor plan image is optional and can be uploaded after the floor exists.
            $table->string('image_path')->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'code']);
        });
        Schema::create('floor_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('rack_id')->nullable()->constrained()->cascadeOnDelete();
            // Fractions of the floor plan image: independent of zoom and of the displayed size.
            $table->double('x');
            $table->double('y');
            $table->unique(['floor_id', 'device_id']);
            $table->unique(['floor_id', 'rack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_items');
        Schema::dropIfExists('floors');
    }
};
