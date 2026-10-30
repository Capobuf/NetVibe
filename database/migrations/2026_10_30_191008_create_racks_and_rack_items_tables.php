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
        Schema::table('device_models', function (Blueprint $table) {
            $table->unsignedSmallInteger('rack_height_u')->nullable();
        });
        Schema::create('racks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('height_u');
            $table->string('numbering', 16);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('rack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rack_id')->constrained()->cascadeOnDelete();
            $table->string('key', 32);
            $table->string('kind', 16);
            $table->foreignId('device_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('shelf_key', 32)->nullable();
            $table->unsignedSmallInteger('position');
            $table->double('height');
            $table->double('width');
            $table->double('x');
            $table->unique(['rack_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rack_items');
        Schema::dropIfExists('racks');
        Schema::table('device_models', fn (Blueprint $table) => $table->dropColumn('rack_height_u'));
    }
};
