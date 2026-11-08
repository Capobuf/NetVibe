<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unifi_connections', function (Blueprint $table) {
            // A direct host belongs to one company; Cloud Connectors stay installation-wide.
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        Schema::table('unifi_sites', function (Blueprint $table) {
            $table->string('last_access', 10)->nullable();
        });

        // Existing direct hosts belong to the company of the sites they already feed.
        foreach (DB::table('unifi_connections')->where('mode', 'direct')->pluck('id') as $id) {
            $company = DB::table('unifi_sites')->join('sites', 'sites.id', '=', 'unifi_sites.site_id')
                ->where('unifi_sites.unifi_connection_id', $id)->orderBy('unifi_sites.id')->value('sites.company_id');
            if ($company) {
                DB::table('unifi_connections')->where('id', $id)->update(['company_id' => $company]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('unifi_sites', fn (Blueprint $table) => $table->dropColumn('last_access'));
        Schema::table('unifi_connections', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
    }
};
