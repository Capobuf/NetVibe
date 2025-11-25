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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('title', 128)->unique();
            $table->string('address', 255)->nullable();
            $table->string('postcode', 32)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('state', 255)->nullable();
            $table->string('lat', 31)->nullable()->comment('Latitude');
            $table->string('long', 31)->nullable()->comment('Longitude');
            $table->text('contact_person')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_mail', 254)->nullable();
            $table->text('note')->nullable();
            $table->enum('status', ['Active', 'Reserved', 'Inactive'])->default('Active');
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
