<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_agent_id')->constrained()->cascadeOnDelete();
            $table->string('level')->index(); // INFO, WARNING, ERROR, CRITICAL
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
            
            // Index for efficient pruning queries
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_logs');
    }
};
