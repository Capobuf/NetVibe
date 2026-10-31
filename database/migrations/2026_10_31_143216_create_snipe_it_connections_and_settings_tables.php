<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snipe_it_connections', function (Blueprint $table) {
            $table->id();
            $table->string('server_url', 2048);
            $table->text('api_key');
            $table->timestamp('checked_at')->nullable();
            $table->string('check_status')->nullable();
            $table->timestamps();
        });
        Schema::create('snipe_it_settings', function (Blueprint $table) {
            $table->string('scope')->primary();
            $table->foreignId('company_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->boolean('use_global')->default(true);
            $table->foreignId('connection_id')->nullable()->constrained('snipe_it_connections');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        DB::table('snipe_it_settings')->insert(['scope' => 'global', 'use_global' => false]);
        Schema::create('snipe_it_company_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('snipe_it_connections');
            $table->unsignedBigInteger('external_company_id');
            $table->string('external_company_name');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snipe_it_company_links');
        Schema::dropIfExists('snipe_it_settings');
        Schema::dropIfExists('snipe_it_connections');
    }
};
