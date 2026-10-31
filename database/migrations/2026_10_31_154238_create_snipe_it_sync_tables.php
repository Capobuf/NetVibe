<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->string('asset_tag')->nullable());
        Schema::create('snipe_it_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('snipe_it_connections');
            $table->unsignedBigInteger('external_company_id');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->unique(['company_id', 'connection_id', 'external_company_id'], 'snipe_sync_identity');
            $table->timestamps();
        });
        Schema::create('snipe_it_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('snipe_it_connections');
            $table->unsignedBigInteger('external_id');
            $table->foreignId('device_model_id')->nullable()->constrained()->nullOnDelete();
            $table->json('snapshot');
            $table->json('applied')->nullable();
            $table->string('problem')->nullable();
            $table->unique(['connection_id', 'external_id']);
            $table->timestamps();
        });
        Schema::create('snipe_it_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_id')->constrained('snipe_it_syncs')->cascadeOnDelete();
            $table->unsignedBigInteger('external_id');
            $table->foreignId('snipe_it_model_id')->nullable()->constrained('snipe_it_models');
            $table->foreignId('device_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->json('snapshot');
            $table->string('state', 20)->default('pending');
            $table->boolean('present')->default(true);
            $table->boolean('needs_confirmation')->default(true);
            $table->string('problem')->nullable();
            $table->unique(['sync_id', 'external_id']);
            $table->index(['sync_id', 'state', 'present']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snipe_it_assets');
        Schema::dropIfExists('snipe_it_models');
        Schema::dropIfExists('snipe_it_syncs');
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('asset_tag'));
    }
};
