<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The Picker app ID is the numeric prefix of the OAuth client ID, so it is no longer stored. */
    public function up(): void
    {
        Schema::table('google_drive_settings', function (Blueprint $table) {
            $table->dropColumn('project_number');
        });
    }

    public function down(): void
    {
        Schema::table('google_drive_settings', function (Blueprint $table) {
            $table->string('project_number', 32)->default('');
        });
    }
};
