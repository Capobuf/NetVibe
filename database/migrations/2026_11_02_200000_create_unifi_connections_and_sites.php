<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unifi_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mode', 10);
            $table->string('server_url', 2048);
            $table->text('api_key');
            $table->boolean('allow_unverified_certificate')->default(false);
            $table->boolean('enabled')->default(true);
            $table->uuid('revision');
            $table->json('hosts')->nullable();
            $table->json('selected_console_ids')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('check_status', 40)->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('unifi_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unifi_connection_id')->constrained()->cascadeOnDelete();
            $table->string('console_id', 255);
            $table->string('console_name');
            $table->uuid('external_site_id');
            $table->string('name');
            $table->string('version', 80);
            $table->boolean('available')->default(true);
            $table->json('preview')->nullable();
            $table->timestamp('discovered_at');
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            // A site UUID already linked through another access method must not cross tenants.
            $table->uuid('mapped_site_uuid')->nullable()->unique();
            $table->timestamps();
            $table->unique(['unifi_connection_id', 'console_id', 'external_site_id'], 'unifi_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unifi_sites');
        Schema::dropIfExists('unifi_connections');
    }
};
