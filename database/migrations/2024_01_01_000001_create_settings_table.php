<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('group')->nullable();
                $table->string('name')->nullable();
                // Spatie Settings (when using DB repository) stores the JSON in 'payload'
                $table->text('payload')->nullable();
                // 'locked' is used by some migrations in this project
                $table->boolean('locked')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
