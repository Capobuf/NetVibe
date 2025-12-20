<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ip_addresses')) {
            return;
        }

        Schema::table('ip_addresses', function (Blueprint $table) {
            // ITAM / discovery columns used by Filament forms
            if (! Schema::hasColumn('ip_addresses', 'switch_id')) {
                // add as foreign id to devices and allow nulls; keep DB integrity with set null on delete
                $table->foreignId('switch_id')->nullable()->after('device_id')->constrained('devices')->onDelete('set null');
            }

            if (! Schema::hasColumn('ip_addresses', 'location_id')) {
                $table->foreignId('location_id')->nullable()->after('switch_id')->constrained('locations')->onDelete('set null');
            }


            if (! Schema::hasColumn('ip_addresses', 'exclude_ping')) {
                $table->boolean('exclude_ping')->default(false)->after('last_seen');
            }

            if (! Schema::hasColumn('ip_addresses', 'ptr_ignore')) {
                $table->boolean('ptr_ignore')->default(false)->after('exclude_ping');
            }

            if (! Schema::hasColumn('ip_addresses', 'ptr')) {
                // use string here so the field can hold textual PTR records or numeric pointers
                $table->string('ptr')->nullable()->after('ptr_ignore');
            }

            if (! Schema::hasColumn('ip_addresses', 'firewall_address_object')) {
                $table->string('firewall_address_object')->nullable()->after('ptr');
            }

            if (! Schema::hasColumn('ip_addresses', 'customer_id')) {
                // add customer_id after firewall_address_object so the column exists first
                $table->foreignId('customer_id')->nullable()->after('firewall_address_object')->constrained('customers')->onDelete('set null');
            }

            // NOTE: port and note are expected by the model/forms — ensure they exist but avoid re-adding if already present
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
            if (Schema::hasColumn('ip_addresses', 'firewall_address_object')) {
                $table->dropColumn('firewall_address_object');
            }

            if (Schema::hasColumn('ip_addresses', 'ptr')) {
                $table->dropColumn('ptr');
            }

            if (Schema::hasColumn('ip_addresses', 'ptr_ignore')) {
                $table->dropColumn('ptr_ignore');
            }

            if (Schema::hasColumn('ip_addresses', 'exclude_ping')) {
                $table->dropColumn('exclude_ping');
            }

            if (Schema::hasColumn('ip_addresses', 'customer_id')) {
                $table->dropConstrainedForeignId('customer_id');
            }

            if (Schema::hasColumn('ip_addresses', 'location_id')) {
                $table->dropConstrainedForeignId('location_id');
            }

            if (Schema::hasColumn('ip_addresses', 'switch_id')) {
                $table->dropConstrainedForeignId('switch_id');
            }

            if (Schema::hasColumn('ip_addresses', 'note')) {
                $table->dropColumn('note');
            }

            if (Schema::hasColumn('ip_addresses', 'port')) {
                $table->dropColumn('port');
            }
        });
    }
};
