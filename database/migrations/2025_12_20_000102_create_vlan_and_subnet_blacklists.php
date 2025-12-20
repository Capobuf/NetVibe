<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // VLAN blacklists are stored by VLAN *number* (not FK to vlans.id) so entries can exist
        // before VLAN import and survive VLAN deletion.
        if (! Schema::hasTable('vlan_blacklists')) {
            Schema::create('vlan_blacklists', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->unsignedSmallInteger('vlan_number');
                $table->string('vlan_name')->nullable();
                $table->string('reason')->nullable();
                $table->timestamps();

                $table->unique(['customer_id', 'vlan_number']);
            });
        }

        if (! Schema::hasTable('subnet_blacklists')) {
            Schema::create('subnet_blacklists', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->string('subnet_cidr', 64);
                $table->string('reason')->nullable();
                $table->timestamps();

                $table->unique(['customer_id', 'subnet_cidr']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vlan_blacklists')) {
            Schema::dropIfExists('vlan_blacklists');
        }

        if (Schema::hasTable('subnet_blacklists')) {
            Schema::dropIfExists('subnet_blacklists');
        }
    }
};
