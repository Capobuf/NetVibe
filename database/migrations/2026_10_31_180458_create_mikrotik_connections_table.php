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
        Schema::create('mikrotik_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('server_url', 2048);
            $table->text('username');
            $table->text('password');
            $table->boolean('allow_unverified_certificate')->default(false);
            $table->uuid('revision');
            $table->timestamp('checked_at')->nullable();
            $table->string('check_status')->nullable();
            $table->json('identity')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->json('analysis')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mikrotik_connections');
    }
};
