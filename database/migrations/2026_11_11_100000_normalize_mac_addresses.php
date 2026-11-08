<?php

use App\Support\MacAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ip_addresses', 'device_interfaces'] as $table) {
            DB::table($table)->whereNotNull('mac_address')->select('id', 'mac_address')->lazyById()
                ->each(function ($row) use ($table) {
                    $mac = MacAddress::normalize($row->mac_address);
                    if ($mac !== $row->mac_address) {
                        DB::table($table)->where('id', $row->id)->update(['mac_address' => $mac]);
                    }
                });
        }
    }

    public function down(): void
    {
        // The original separators and case are not recoverable; canonical values remain valid.
    }
};
