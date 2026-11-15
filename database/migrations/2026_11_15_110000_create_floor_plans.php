<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floor_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Same optional code as racks.floor, so racks can be matched to their floor plan.
            $table->string('floor', 12)->nullable();
            $table->string('image_path');
            $table->unsignedInteger('image_width');
            $table->unsignedInteger('image_height');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'name']);
        });
        Schema::create('floor_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('rack_id')->nullable()->constrained()->cascadeOnDelete();
            // Fractions of the image size: independent of zoom and of the displayed size.
            $table->double('x');
            $table->double('y');
            $table->unique(['floor_plan_id', 'device_id']);
            $table->unique(['floor_plan_id', 'rack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_plan_items');
        Schema::dropIfExists('floor_plans');
    }
};
