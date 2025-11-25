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
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('master_section_id')->nullable()->default(0);
            $table->text('permissions')->nullable()->comment('JSON permissions object');
            $table->boolean('strict_mode')->default(true);
            $table->string('subnet_ordering', 16)->nullable();
            $table->unsignedTinyInteger('order')->nullable();
            $table->boolean('show_subnet')->default(true);
            $table->boolean('show_vlan')->default(false);
            $table->boolean('show_vrf')->default(false);
            $table->boolean('show_supernet_only')->default(false);
            $table->string('dns', 128)->nullable();
            $table->timestamps();
            
            $table->index('name');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
