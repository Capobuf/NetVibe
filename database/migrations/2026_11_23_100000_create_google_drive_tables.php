<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single row (id 1): the Google Cloud project NetVibe uses, configured from administration.
        Schema::create('google_drive_settings', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->text('client_secret');
            $table->text('api_key');
            $table->string('project_number', 32);
            $table->timestamps();
        });

        Schema::create('google_drive_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->text('refresh_token');
            $table->timestamps();
        });

        Schema::create('google_drive_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // A disconnected account leaves the destination in place, waiting for another account.
            $table->foreignId('account_id')->nullable()->constrained('google_drive_accounts')->nullOnDelete();
            $table->string('folder_id');
            $table->string('folder_name');
            $table->unsignedSmallInteger('interval');
            $table->string('file_id')->nullable();
            $table->string('file_url', 500)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_google_drive')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_google_drive');
        });
        Schema::dropIfExists('google_drive_syncs');
        Schema::dropIfExists('google_drive_accounts');
        Schema::dropIfExists('google_drive_settings');
    }
};
