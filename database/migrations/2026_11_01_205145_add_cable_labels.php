<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('racks', fn (Blueprint $table) => $table->string('floor', 12)->nullable());
        Schema::table('cables', fn (Blueprint $table) => $table->string('name')->nullable());
        Schema::create('cable_label_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('width');
            $table->string('cable_type', 10);
            $table->json('settings');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['width', 'cable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cable_label_templates');
        Schema::table('cables', fn (Blueprint $table) => $table->dropColumn('name'));
        Schema::table('racks', fn (Blueprint $table) => $table->dropColumn('floor'));
    }
};
