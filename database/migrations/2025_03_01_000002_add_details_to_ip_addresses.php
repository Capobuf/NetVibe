<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ip_addresses')) {
            Log::warning('add_details_to_ip_addresses: ip_addresses table missing, skipping');
            return;
        }

        Schema::table('ip_addresses', function (Blueprint $table) {
            // If an old legacy `mac` column exists, prefer renaming it to `mac_address`
            if (Schema::hasColumn('ip_addresses', 'mac') && ! Schema::hasColumn('ip_addresses', 'mac_address')) {
                // Try to rename gracefully; some DB drivers may require doctrine/dbal for rename -> fall back to creating a new column
                try {
                    $table->renameColumn('mac', 'mac_address');
                } catch (\Throwable $e) {
                    // If rename fails (no dbal), create the new column and leave legacy column in place
                    if (! Schema::hasColumn('ip_addresses', 'mac_address')) {
                        $table->string('mac_address')->nullable()->after('hostname');
                    }
                }
            } elseif (! Schema::hasColumn('ip_addresses', 'mac_address')) {
                $table->string('mac_address')->nullable()->after('hostname');
            }

            if (! Schema::hasColumn('ip_addresses', 'owner')) {
                $table->string('owner')->nullable()->after('mac_address');
            }

            if (! Schema::hasColumn('ip_addresses', 'port')) {
                $table->string('port')->nullable()->after('owner');
            }

            if (! Schema::hasColumn('ip_addresses', 'note')) {
                $table->text('note')->nullable()->after('port');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ip_addresses')) {
            return;
        }

        Schema::table('ip_addresses', function (Blueprint $table) {
            if (Schema::hasColumn('ip_addresses', 'note')) {
                $table->dropColumn('note');
            }
            if (Schema::hasColumn('ip_addresses', 'port')) {
                $table->dropColumn('port');
            }
            if (Schema::hasColumn('ip_addresses', 'owner')) {
                $table->dropColumn('owner');
            }
            if (Schema::hasColumn('ip_addresses', 'mac_address')) {
                // If the legacy 'mac' column does not exist, dropping mac_address is safe
                $table->dropColumn('mac_address');
            }
        });
    }
};
