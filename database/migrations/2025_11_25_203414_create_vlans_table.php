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
        Schema::create('vlans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('domain_id')->default(1)->comment('FK to vlan_domains');
            $table->string('name', 255);
            $table->unsignedSmallInteger('number')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();
            
            // Indexes
            $table->index('domain_id');
            $table->index('customer_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vlans');
    }
};
