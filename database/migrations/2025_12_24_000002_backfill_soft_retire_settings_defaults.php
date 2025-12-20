<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('settings')->where('group', 'discovery')->get();

        foreach ($rows as $row) {
            $payload = json_decode($row->payload ?? '{}', true) ?: [];
            $updated = false;

            if (! array_key_exists('inventory_soft_retire_enabled', $payload)) {
                $payload['inventory_soft_retire_enabled'] = true;
                $updated = true;
            }

            if (! array_key_exists('inventory_stale_after_days', $payload)) {
                $payload['inventory_stale_after_days'] = 15;
                $updated = true;
            }

            if (! array_key_exists('inventory_retire_after_days', $payload)) {
                $payload['inventory_retire_after_days'] = 45;
                $updated = true;
            }

            if ($updated) {
                DB::table('settings')->where('id', $row->id)->update([
                    'payload' => json_encode($payload),
                ]);
            }
        }
    }

    public function down(): void
    {
        // No-op: keep enriched defaults.
    }
};
