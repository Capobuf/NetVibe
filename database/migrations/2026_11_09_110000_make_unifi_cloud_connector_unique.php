<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('unifi_connections')->where('mode', 'cloud')->update(['name' => 'UniFi Cloud Connector']);
        Schema::table('unifi_connections', function (Blueprint $table) {
            // One Cloud Connector per installation: direct hosts leave the column null, which is not unique-checked.
            $table->boolean('cloud_connector')->nullable()->storedAs("IF(mode = 'cloud', 1, NULL)")->unique();
        });
    }

    public function down(): void
    {
        Schema::table('unifi_connections', fn (Blueprint $table) => $table->dropColumn('cloud_connector'));
    }
};
