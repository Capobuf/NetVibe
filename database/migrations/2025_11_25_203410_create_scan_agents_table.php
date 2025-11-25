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
        Schema::create('scan_agents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->nullable();
            $table->text('description')->nullable();
            $table->enum('type', ['direct', 'api', 'mysql'])->default('direct');
            $table->string('code', 32)->nullable()->unique()->comment('API authentication token');
            $table->timestamp('last_access')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_agents');
    }
};
