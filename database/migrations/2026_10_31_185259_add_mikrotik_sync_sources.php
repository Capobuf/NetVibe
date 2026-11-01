<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_connections', function (Blueprint $table) {
            $table->uuid('source_generation')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('sync_attempted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('sync_status')->nullable();
            $table->json('sync_identity')->nullable();
            $table->json('sync_summary')->nullable();
        });
        Schema::table('devices', fn (Blueprint $table) => $table->unique(['site_id', 'id']));
        Schema::table('subnets', fn (Blueprint $table) => $table->unique(['site_id', 'id']));
        Schema::table('ip_addresses', fn (Blueprint $table) => $table->unique(['subnet_id', 'id']));
        Schema::create('mikrotik_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('device_id');
            $table->uuid('generation');
            $table->string('kind', 24);
            $table->string('source_key', 191);
            $table->string('state', 16)->default('current');
            $table->json('data');
            $table->json('anchor');
            $table->string('edit_mode', 16)->nullable();
            $table->json('expected')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('seen_at');
            $table->text('problem')->nullable();
            $table->unsignedInteger('address')->nullable();
            $table->unsignedBigInteger('vlan_id')->nullable();
            $table->unsignedBigInteger('subnet_id')->nullable();
            $table->unsignedBigInteger('ip_address_id')->nullable();
            $table->boolean('linked')->default(false);
            $table->boolean('confirmed')->default(false);
            $table->timestamps();
            $table->unique(['device_id', 'generation', 'kind', 'source_key'], 'mikrotik_source_identity');
            $table->index(['site_id', 'state', 'address']);
            $table->foreign(['site_id', 'device_id'])->references(['site_id', 'id'])->on('devices')->cascadeOnDelete();
            $table->foreign(['site_id', 'vlan_id'])->references(['site_id', 'id'])->on('vlans')->restrictOnDelete();
            $table->foreign(['site_id', 'subnet_id'])->references(['site_id', 'id'])->on('subnets')->restrictOnDelete();
            $table->foreign(['subnet_id', 'ip_address_id'])->references(['subnet_id', 'id'])->on('ip_addresses')->nullOnDelete();
        });
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->string('mikrotik_edit_mode', 16)->nullable();
            $table->json('mikrotik_expected')->nullable();
            $table->timestamp('mikrotik_matched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_sources');
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropUnique(['subnet_id', 'id']);
            $table->dropColumn(['mikrotik_edit_mode', 'mikrotik_expected', 'mikrotik_matched_at']);
        });
        Schema::table('subnets', fn (Blueprint $table) => $table->dropUnique(['site_id', 'id']));
        Schema::table('devices', fn (Blueprint $table) => $table->dropUnique(['site_id', 'id']));
        Schema::table('mikrotik_connections', fn (Blueprint $table) => $table->dropColumn([
            'source_generation', 'activated_at', 'sync_attempted_at', 'synced_at', 'sync_status', 'sync_identity', 'sync_summary',
        ]));
    }
};
