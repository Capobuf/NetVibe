<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('devices')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table): void {
            if (! Schema::hasColumn('devices', 'last_seen_rest')) {
                $table->timestamp('last_seen_rest')->nullable()->after('last_seen_at');
            }

            if (! Schema::hasColumn('devices', 'last_seen_snmp')) {
                $table->timestamp('last_seen_snmp')->nullable()->after('last_seen_rest');
            }

            if (! Schema::hasColumn('devices', 'last_seen_icmp')) {
                $table->timestamp('last_seen_icmp')->nullable()->after('last_seen_snmp');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('devices')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table): void {
            foreach (['last_seen_rest', 'last_seen_snmp', 'last_seen_icmp'] as $col) {
                if (Schema::hasColumn('devices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

