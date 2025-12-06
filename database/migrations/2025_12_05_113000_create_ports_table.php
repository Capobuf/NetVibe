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
        Schema::create('ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('rj45');
            $table->string('mac_address')->nullable();
            $table->enum('status', ['active', 'down', 'error'])->default('down');
            $table->foreignId('connected_to_port_id')->nullable()->constrained('ports')->nullOnDelete();
            $table->timestamps();
            $table->index('device_id');
            $table->index(['device_id', 'name']);
            $table->softDeletes();
            $table->index('connected_to_port_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ports');
    }
};
