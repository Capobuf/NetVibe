<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ip_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        DB::table('ip_statuses')->insert([
            ['name' => 'Assegnato', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Riservato', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->foreignId('ip_status_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unique(['company_id', 'name']);
            $table->timestamps();
        });

        Schema::create('ip_address_tag', function (Blueprint $table) {
            $table->foreignId('ip_address_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['ip_address_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_address_tag');
        Schema::dropIfExists('tags');
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ip_status_id');
        });
        Schema::dropIfExists('ip_statuses');
    }
};
