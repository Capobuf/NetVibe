<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedSmallInteger('observation_retention_days')->default(30);
        });
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->boolean('auto_managed')->default(false);
        });
        foreach (['mikrotik_sources', 'unifi_observations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('present_at')->nullable();
            });
        }
        // The last read is the only presence evidence existing rows carry, and only where it proves presence.
        DB::table('mikrotik_sources')->where('state', 'current')->whereNotNull('address')
            ->where(fn ($query) => $query->where('kind', 'address')
                ->orWhereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.menu'))"), ['/ip/arp', '/ip/neighbor'])
                ->orWhere(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.status'))"), 'bound'))
            ->update(['present_at' => DB::raw('seen_at')]);
        DB::table('unifi_observations')->where('state', 'current')->whereNotNull('address')
            ->where(fn ($query) => $query->where('kind', '!=', 'device')
                ->orWhere(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.state'))"), 'ONLINE'))
            ->update(['present_at' => DB::raw('seen_at')]);
    }

    public function down(): void
    {
        foreach (['mikrotik_sources', 'unifi_observations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('present_at'));
        }
        Schema::table('ip_addresses', fn (Blueprint $table) => $table->dropColumn('auto_managed'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('observation_retention_days'));
    }
};
