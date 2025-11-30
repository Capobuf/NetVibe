<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create l2_domains table
        if (! Schema::hasTable('l2_domains')) {
            Schema::create('l2_domains', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Create vlans table
        if (! Schema::hasTable('vlans')) {
            Schema::create('vlans', function (Blueprint $table) {
                $table->id();
                // allow VLANs to be global (no domain) but prefer relation
                $table->unsignedBigInteger('l2_domain_id')->nullable();
                $table->integer('number');
                $table->string('name');
                $table->text('description')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                // foreign keys (guard with existence checks in case migrations ordering varies)
                if (Schema::hasTable('l2_domains')) {
                    $table->foreign('l2_domain_id')->references('id')->on('l2_domains')->onDelete('set null');
                }

                if (Schema::hasTable('customers')) {
                    $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
                }

                $table->unique(['l2_domain_id', 'number']);
                $table->index('customer_id');
            });
        }

        // Add vlan_id to subnets when missing
        if (Schema::hasTable('subnets') && ! Schema::hasColumn('subnets', 'vlan_id')) {
            Schema::table('subnets', function (Blueprint $table) {
                if (Schema::hasColumn('subnets', 'scan_agent_id')) {
                    $table->unsignedBigInteger('vlan_id')->nullable()->after('scan_agent_id');
                } else {
                    $table->unsignedBigInteger('vlan_id')->nullable();
                }
                $table->index('vlan_id');
                if (Schema::hasTable('vlans')) {
                    $table->foreign('vlan_id')->references('id')->on('vlans')->onDelete('set null');
                }
            });
        }
    }

    public function down(): void
    {
        // Remove foreign key and column from subnets
        if (Schema::hasTable('subnets') && Schema::hasColumn('subnets', 'vlan_id')) {
            Schema::table('subnets', function (Blueprint $table) {
                try {
                    $table->dropForeign(['vlan_id']);
                } catch (\Throwable $e) {
                    // ignore if FK not present
                }

                try {
                    $table->dropIndex(['vlan_id']);
                } catch (\Throwable $_) {
                    // ignore
                }

                $table->dropColumn('vlan_id');
            });
        }

        // Drop vlans table
        if (Schema::hasTable('vlans')) {
            Schema::dropIfExists('vlans');
        }

        // Drop l2_domains table
        if (Schema::hasTable('l2_domains')) {
            Schema::dropIfExists('l2_domains');
        }
    }
};
