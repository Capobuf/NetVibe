<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('device_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->json('panel')->nullable();
            $table->json('assets')->nullable();
            $table->unsignedInteger('panel_revision')->default(0);
            $table->unique(['manufacturer_id', 'name']);
            $table->timestamps();
        });
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('manufacturer_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_model_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('device_interfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('connector_id', 64)->nullable();
            $table->string('name')->nullable();
            $table->string('kind', 20)->default('physical');
            $table->string('mac_address', 17)->nullable();
            $table->unique(['device_id', 'connector_id']);
            $table->unique(['id', 'device_id']);
            $table->timestamps();
        });
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('device_interface_id')->nullable();
            $table->foreign(['device_interface_id', 'device_id'], 'ip_device_interface_fk')->references(['id', 'device_id'])->on('device_interfaces')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE ip_addresses ADD CONSTRAINT ip_interface_requires_device CHECK (device_interface_id IS NULL OR device_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ip_addresses DROP CHECK ip_interface_requires_device');
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropForeign('ip_device_interface_fk');
            $table->dropColumn('device_interface_id');
            $table->dropConstrainedForeignId('device_id');
        });
        Schema::dropIfExists('device_interfaces');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('device_models');
        Schema::dropIfExists('manufacturers');
    }
};
