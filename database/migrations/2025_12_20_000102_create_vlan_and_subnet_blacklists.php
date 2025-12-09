<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vlan_blacklists')) {
            Schema::create('vlan_blacklists', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('vlan_id')->constrained('vlans')->cascadeOnDelete();
                $table->string('reason')->nullable();
                $table->timestamps();

                $table->unique(['customer_id', 'vlan_id']);
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

