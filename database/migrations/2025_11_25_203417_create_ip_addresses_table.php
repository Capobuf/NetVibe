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
        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subnet_id')->nullable()->constrained('subnets')->onDelete('cascade');
            $table->string('ip_addr', 100)->comment('IP address in decimal format');
            $table->boolean('is_gateway')->default(false);
            $table->string('description', 64)->nullable();
            $table->string('hostname', 255)->nullable();
            $table->string('mac', 20)->nullable();
            $table->string('owner', 128)->nullable();
            $table->unsignedTinyInteger('state')->nullable()->default(2)->comment('1=Offline, 2=Used, 3=Reserved, 4=DHCP');
            
            $table->unsignedBigInteger('switch_id')->nullable()->comment('FK to devices');
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('port', 32)->nullable();
            $table->text('note')->nullable();
            
            // Scan-related
            $table->timestamp('last_seen')->nullable()->default('1970-01-01 00:00:01');
            $table->boolean('exclude_ping')->default(false);
            
            // DNS-related
            $table->boolean('ptr_ignore')->default(false);
            $table->unsignedBigInteger('ptr')->nullable()->default(0);
            
            $table->string('firewall_address_object', 100)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();
            
            // Indexes
            $table->unique(['ip_addr', 'subnet_id'], 'sid_ip_unique');
            $table->index('subnet_id');
            $table->index('location_id');
            $table->index('customer_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
    }
};
