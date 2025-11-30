<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('ip_addresses')) {
            Log::warning('purge_empty_ips: ip_addresses table missing, skipping');
            return;
        }

        // Safety: Only delete rows that look like prefilled placeholders.
        // Some deployments may not yet have all columns (eg: `state`, `type`),
        // so validate column presence before building the query. If we cannot
        // reliably identify placeholder rows (missing crucial columns such as
        // `type` or `state`) then skip this destructive operation.

        $schema = DB::getSchemaBuilder();

        if (! $schema->hasColumn('ip_addresses', 'type') || ! $schema->hasColumn('ip_addresses', 'state')) {
            Log::warning('purge_empty_ips: ip_addresses missing `type` and/or `state` columns, skipping to avoid accidental deletes');
            return;
        }

        $query = DB::table('ip_addresses');

        // Apply optional column checks only if those columns exist
        if ($schema->hasColumn('ip_addresses', 'description')) {
            $query = $query->whereNull('description');
        }
        if ($schema->hasColumn('ip_addresses', 'hostname')) {
            $query = $query->whereNull('hostname');
        }
        if ($schema->hasColumn('ip_addresses', 'device_id')) {
            $query = $query->whereNull('device_id');
        }

        $query = $query->where('type', 'host')->where('state', 0);

        $total = $query->count();
        if ($total === 0) {
            Log::info('purge_empty_ips: no matching placeholder rows found');
            return;
        }

        Log::info('purge_empty_ips: removing placeholder ip rows', ['count' => $total]);

        // Delete in chunks to avoid locking large tables.
        $query->orderBy('id')->chunkById(500, function ($rows) {
            $ids = $rows->pluck('id')->all();
            DB::table('ip_addresses')->whereIn('id', $ids)->delete();
        });

        Log::info('purge_empty_ips: completed');
    }

    public function down(): void
    {
        // This migration is destructive (data deletion). Down cannot restore removed rows.
        Log::warning('purge_empty_ips: down() is a no-op because this operation is destructive');
    }
};
