<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            // Visible part of the plan, as fractions of the uploaded image: the file itself is never cut.
            $table->double('crop_x')->nullable();
            $table->double('crop_y')->nullable();
            $table->double('crop_width')->nullable();
            $table->double('crop_height')->nullable();
            // Image pixels per metre, from a known distance: it gives real sizes to the footprints.
            $table->double('scale')->nullable();
        });
        Schema::table('floor_items', function (Blueprint $table) {
            $table->boolean('hidden')->default(false);
            $table->boolean('locked')->default(false);
            // Footprint in metres, centred on the position; null draws a point.
            $table->double('width')->nullable();
            $table->double('height')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('floor_items', fn (Blueprint $table) => $table->dropColumn(['hidden', 'locked', 'width', 'height']));
        Schema::table('floors', fn (Blueprint $table) => $table->dropColumn(['crop_x', 'crop_y', 'crop_width', 'crop_height', 'scale']));
    }
};
