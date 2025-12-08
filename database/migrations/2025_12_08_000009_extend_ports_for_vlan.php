<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ports')) {
            Schema::table('ports', function (Blueprint $table) {
                if (! Schema::hasColumn('ports', 'vlan_mode')) {
                    $table->string('vlan_mode')->nullable()->after('type');
                }

                if (! Schema::hasColumn('ports', 'pvid')) {
                    $table->integer('pvid')->nullable()->after('vlan_mode');
                }

                if (! Schema::hasColumn('ports', 'tagged_vlans')) {
                    $table->json('tagged_vlans')->nullable()->after('pvid');
                }

                if (! Schema::hasColumn('ports', 'mtu')) {
                    $table->integer('mtu')->nullable()->after('last_seen');
                }

                if (! Schema::hasColumn('ports', 'speed')) {
                    $table->string('speed')->nullable()->after('mtu');
                }

                if (! Schema::hasColumn('ports', 'duplex')) {
                    $table->string('duplex')->nullable()->after('speed');
                }

                if (! Schema::hasColumn('ports', 'external_type')) {
                    $table->string('external_type')->nullable()->after('duplex');
                }

                if (! Schema::hasColumn('ports', 'description')) {
                    $table->string('description')->nullable()->after('external_type');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ports')) {
            Schema::table('ports', function (Blueprint $table) {
                foreach (['vlan_mode','pvid','tagged_vlans','mtu','speed','duplex','external_type','description'] as $col) {
                    if (Schema::hasColumn('ports', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
