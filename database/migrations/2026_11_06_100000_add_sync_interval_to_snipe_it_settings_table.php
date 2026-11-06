<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('snipe_it_settings', function (Blueprint $table) {
            // Minutes between automatic syncs; read from the global row only.
            $table->unsignedSmallInteger('sync_interval')->default(60)->after('connection_id');
        });
    }

    public function down(): void
    {
        Schema::table('snipe_it_settings', function (Blueprint $table) {
            $table->dropColumn('sync_interval');
        });
    }
};
