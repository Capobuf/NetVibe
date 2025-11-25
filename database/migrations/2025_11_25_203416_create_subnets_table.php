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
        Schema::create('subnets', function (Blueprint $table) {
            $table->id();
            $table->string('subnet', 255)->nullable()->comment('IP subnet (decimal format for IPv4/IPv6)');
            $table->string('mask', 3)->nullable()->comment('CIDR mask (e.g., 24 for /24)');
            $table->foreignId('section_id')->nullable()->constrained('sections')->onDelete('cascade');
            $table->text('description')->nullable();
            
            $table->unsignedBigInteger('linked_subnet_id')->nullable();
            $table->string('firewall_address_object', 100)->nullable();
            $table->foreignId('vrf_id')->nullable()->constrained('vrfs')->nullOnDelete();
            $table->unsignedBigInteger('master_subnet_id')->default(0)->comment('Parent subnet for nested subnets');
            
            $table->boolean('allow_requests')->default(false)->comment('Allow IP requests for this subnet');
            $table->foreignId('vlan_id')->nullable()->constrained('vlans')->nullOnDelete();
            $table->boolean('show_name')->default(false);
            $table->unsignedBigInteger('device_id')->nullable();
            
            $table->text('permissions')->nullable()->comment('JSON permissions object');
            
            // Scan settings
            $table->boolean('ping_subnet')->default(false)->comment('Enable ping monitoring');
            $table->boolean('discover_subnet')->default(false)->comment('Enable discovery scans');
            $table->boolean('resolve_dns')->default(false);
            $table->boolean('dns_recursive')->default(false);
            $table->boolean('dns_records')->default(false);
            $table->unsignedBigInteger('nameserver_id')->nullable()->default(0);
            $table->foreignId('scan_agent_id')->nullable()->constrained('scan_agents')->nullOnDelete();
            
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            
            // Folder/Pool flags
            $table->boolean('is_folder')->default(false)->comment('Folder for organizing subnets');
            $table->boolean('is_full')->default(false);
            $table->boolean('is_pool')->default(false);
            
            $table->unsignedTinyInteger('state')->nullable()->default(2)->comment('1=Used, 2=Reserved, 3=Offline, 4=DHCP');
            $table->unsignedTinyInteger('threshold')->nullable()->default(0)->comment('Subnet utilization threshold');
            
            $table->timestamp('last_scan')->nullable();
            $table->timestamp('last_discovery')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('section_id');
            $table->index('master_subnet_id');
            $table->index('vrf_id');
            $table->index('customer_id');
            $table->index('location_id');
            $table->index('scan_agent_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subnets');
    }
};
