<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unifi_sites', function (Blueprint $table) {
            $table->timestamp('inventory_activated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 40)->nullable();
        });
        Schema::create('unifi_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unifi_site_id')->constrained()->cascadeOnDelete();
            $table->uuid('external_id');
            $table->foreignId('device_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->boolean('present')->default(true);
            $table->json('observed');
            $table->json('ports')->nullable();
            $table->json('maintained')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['unifi_site_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unifi_devices');
        Schema::table('unifi_sites', fn (Blueprint $table) => $table->dropColumn(['inventory_activated_at', 'last_synced_at', 'sync_status']));
    }
};
