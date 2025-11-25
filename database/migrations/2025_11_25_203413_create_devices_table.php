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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('hostname', 255)->nullable();
            $table->string('ip_addr', 100)->nullable();
            $table->unsignedTinyInteger('type')->default(0)->comment('FK to device_types');
            $table->string('description', 256)->nullable();
            $table->text('sections')->nullable()->comment('JSON array of section IDs');
            
            // SNMP fields (optional, can be enabled/disabled)
            $table->string('snmp_community', 100)->nullable();
            $table->set('snmp_version', ['0', '1', '2', '3'])->default('0');
            $table->unsignedMediumInteger('snmp_port')->default(161);
            $table->unsignedMediumInteger('snmp_timeout')->default(1000);
            $table->string('snmp_queries', 128)->nullable();
            
            // SNMP v3 fields
            $table->set('snmp_v3_sec_level', ['none', 'noAuthNoPriv', 'authNoPriv', 'authPriv'])->default('none');
            $table->set('snmp_v3_auth_protocol', ['none', 'MD5', 'SHA'])->default('none');
            $table->string('snmp_v3_auth_pass', 64)->nullable();
            $table->set('snmp_v3_priv_protocol', ['none', 'DES', 'AES'])->default('none');
            $table->string('snmp_v3_priv_pass', 64)->nullable();
            $table->string('snmp_v3_ctx_name', 64)->nullable();
            $table->string('snmp_v3_ctx_engine_id', 64)->nullable();
            
            // Rack position (if racks enabled)
            $table->unsignedBigInteger('rack_id')->nullable();
            $table->unsignedBigInteger('rack_start')->nullable();
            $table->unsignedBigInteger('rack_size')->nullable();
            
            $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('cascade');
            $table->timestamps();
            
            // Indexes
            $table->index('hostname');
            $table->index('location_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
