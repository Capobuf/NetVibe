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
        Schema::create('discovery_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scan_agent_id')->nullable()->index();
            $table->string('ip_addr');
            $table->string('mac_address_current')->nullable();
            $table->string('hostname_current')->nullable();
            $table->enum('status', ['PENDING','APPROVED','DISCARDED'])->default('PENDING');
            $table->string('conflict_type')->nullable();
            $table->json('payload_raw')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('scan_agent_id')->references('id')->on('scan_agents')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovery_events');
    }
};
