<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize existing Filament-formatted notifications to always include an actions array with name keys
        DB::table('notifications')
            ->whereRaw("JSON_EXTRACT(data, '$.format') = 'filament'")
            ->orderBy('id')
            ->chunkById(200, function ($chunk) {
                foreach ($chunk as $row) {
                    $data = json_decode($row->data, true);
                    if (! is_array($data)) {
                        continue;
                    }

                    $actions = $data['actions'] ?? [];
                    $needsUpdate = false;

                    // Ensure actions is an array of arrays
                    if (! is_array($actions)) {
                        $actions = [];
                        $needsUpdate = true;
                    }

                    $normalized = [];
                    foreach ($actions as $idx => $action) {
                        if (! is_array($action)) {
                            $needsUpdate = true;
                            continue;
                        }

                        if (! array_key_exists('name', $action)) {
                            $action['name'] = $action['label'] ?? ('action_' . $idx);
                            $needsUpdate = true;
                        }

                        $normalized[] = $action;
                    }

                    if ($needsUpdate) {
                        $data['actions'] = $normalized;
                        DB::table('notifications')
                            ->where('id', $row->id)
                            ->update(['data' => json_encode($data)]);
                    }
                }
            });
    }

    public function down(): void
    {
        // no-op; data-only migration
    }
};
