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

            if (! array_key_exists('agent_polling_interval_minutes', $payload)) {
                $payload['agent_polling_interval_minutes'] = 5;
                $updated = true;
            }

            if (! array_key_exists('prune_logs_days', $payload)) {
                $payload['prune_logs_days'] = 3;
                $updated = true;
            }

            if ($updated) {
                DB::table('settings')
                    ->where('id', $row->id)
                    ->update(['payload' => json_encode($payload)]);
            }
        }
    }

    public function down(): void
    {
        // No-op: leave enriched defaults in place
    }
};
