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
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('tag');
            $table->string('name')->nullable();
            $table->unique(['site_id', 'tag']);
            $table->unique(['site_id', 'id']);
            $table->timestamps();
        });

        Schema::create('subnets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('vlan_id')->nullable();
            $table->string('cidr', 18);
            $table->unsignedInteger('network_address');
            $table->unsignedInteger('broadcast_address');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->index(['site_id', 'network_address']);
            // A subnet cannot reference a VLAN from another site, including through direct SQL.
            $table->foreign(['site_id', 'vlan_id'])->references(['site_id', 'id'])->on('vlans')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subnets');
        Schema::dropIfExists('vlans');
    }
};
