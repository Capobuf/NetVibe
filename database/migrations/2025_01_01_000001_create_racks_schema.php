<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // device_models
        if (! Schema::hasTable('device_models')) {
            Schema::create('device_models', function (Blueprint $table) {
                $table->id();
                $table->string('brand');
                $table->string('model');
                $table->string('type');
                $table->integer('u_size')->default(1);
                $table->integer('ethernet_ports')->default(0);
                $table->integer('sfp_ports')->default(0);
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // racks
        if (! Schema::hasTable('racks')) {
            Schema::create('racks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('location_id')->constrained('locations')->onDelete('restrict');
                $table->string('name');
                $table->integer('units')->default(42);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // devices
        if (! Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rack_id')->nullable()->constrained('racks')->onDelete('restrict');
                $table->foreignId('device_model_id')->constrained('device_models')->onDelete('restrict');
                $table->string('hostname');
                $table->string('ip_addr')->nullable();
                $table->string('serial_number')->nullable();
                $table->integer('rack_position')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('devices')) {
            Schema::dropIfExists('devices');
        }

        if (Schema::hasTable('racks')) {
            Schema::dropIfExists('racks');
        }

        if (Schema::hasTable('device_models')) {
            Schema::dropIfExists('device_models');
        }
    }
};
