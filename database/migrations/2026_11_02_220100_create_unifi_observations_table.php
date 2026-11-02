<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unifi_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unifi_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('source_key', 100);
            $table->string('state', 16)->default('current');
            $table->json('data');
            $table->json('anchor')->nullable();
            $table->unsignedBigInteger('address')->nullable();
            $table->foreignId('vlan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subnet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ip_address_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('linked')->default(false);
            $table->string('problem')->nullable();
            $table->string('decision', 16)->nullable();
            $table->string('edit_mode', 16)->nullable();
            $table->json('expected')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();
            $table->unique(['unifi_site_id', 'kind', 'source_key']);
            $table->index(['site_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unifi_observations');
    }
};
