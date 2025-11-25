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
        Schema::create('vrfs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->string('rd', 32)->nullable()->comment('Route Distinguisher');
            $table->string('description', 256)->nullable();
            $table->string('sections', 128)->nullable()->comment('JSON array of section IDs');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vrfs');
    }
};
