<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fast-fail migration path: convert legacy schema (customer_id + vlan_id FK) to
        // the desired schema (customer_id + vlan_number), so blacklist can exist before VLAN import
        // and survive VLAN deletion.
        if (! Schema::hasTable('vlan_blacklists')) {
            return;
        }

        // Only migrate if legacy column exists.
        if (! Schema::hasColumn('vlan_blacklists', 'vlan_id')) {
            return;
        }

        // If the table already has vlan_number, assume it's already migrated.
        if (Schema::hasColumn('vlan_blacklists', 'vlan_number')) {
            return;
        }

        // Create new table with the target schema.
        Schema::create('vlan_blacklists_tmp', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedSmallInteger('vlan_number');
            $table->string('vlan_name')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'vlan_number']);
        });

        $rows = DB::table('vlan_blacklists')->get();
        foreach ($rows as $row) {
            $vlan = DB::table('vlans')->where('id', $row->vlan_id)->first();
            if (! $vlan || ! isset($vlan->number)) {
                throw new RuntimeException('Cannot migrate vlan_blacklists: unable to resolve vlan_id=' . (string) $row->vlan_id . ' to a VLAN number (fast-fail).');
            }

            DB::table('vlan_blacklists_tmp')->insert([
                'customer_id' => $row->customer_id,
                'vlan_number' => (int) $vlan->number,
                'vlan_name' => $vlan->name ?? null,
                'reason' => $row->reason ?? null,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }

        // Replace old table.
        Schema::drop('vlan_blacklists');
        Schema::rename('vlan_blacklists_tmp', 'vlan_blacklists');
    }

    public function down(): void
    {
        /*
         * No-op: the legacy FK-based schema (vlan_id) cannot be reliably reconstructed
         * because the new model allows blacklisting VLAN numbers before VLAN import.
         */
    }
};
