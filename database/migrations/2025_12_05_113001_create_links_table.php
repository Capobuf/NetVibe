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
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('port_a_id')->constrained('ports')->onDelete('cascade');
            $table->foreignId('port_b_id')->constrained('ports')->onDelete('cascade');
            $table->foreignId('cable_type_id')->nullable()->constrained('cable_types')->nullOnDelete();
            $table->decimal('length', 8, 2)->nullable();
            $table->string('color')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['port_a_id', 'port_b_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
