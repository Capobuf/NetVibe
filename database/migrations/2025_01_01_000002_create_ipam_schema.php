<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Subnets
        if (! Schema::hasTable('subnets')) {
            Schema::create('subnets', function (Blueprint $table) {
                $table->id();
                // VRF tenancy required
                $table->foreignId('vrf_id')->constrained('vrfs')->onDelete('restrict');
                $table->string('name')->nullable();
                // store numeric network as decimal string for IPv6-safe (39 digits)
                $table->decimal('subnet', 39, 0);
                $table->integer('mask');
                $table->string('cidr')->nullable();
                // hierarchical nesting (master_subnet_id used in repo)
                $table->foreignId('master_subnet_id')->nullable()->constrained('subnets')->onDelete('restrict');
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['vrf_id', 'subnet']);
            });
        }

        // IP Addresses
        if (! Schema::hasTable('ip_addresses')) {
            Schema::create('ip_addresses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subnet_id')->constrained('subnets')->onDelete('cascade');
                $table->decimal('ip_addr', 39, 0);
                $table->string('ip_str')->nullable();
                $table->enum('type', ['host', 'gateway', 'broadcast', 'reserved', 'dhcp'])->default('host');
                $table->string('hostname')->nullable();
                $table->text('description')->nullable();
                $table->foreignId('device_id')->nullable()->constrained('devices')->onDelete('set null');
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['subnet_id', 'ip_addr']);
                $table->index('ip_addr');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ip_addresses')) {
            Schema::dropIfExists('ip_addresses');
        }

        if (Schema::hasTable('subnets')) {
            Schema::dropIfExists('subnets');
        }
    }
};
