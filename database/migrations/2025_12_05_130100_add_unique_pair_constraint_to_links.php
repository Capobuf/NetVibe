<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add generated persisted columns to normalize unordered pair of ports
        // and add a unique constraint on the normalized pair to avoid duplicates (A-B and B-A)
        // Note: using SQL because Laravel's schema builder does not provide a cross-vendor API for generated columns.

        // Add generated columns port_low and port_high (stored) computed from LEAST/GREATEST
        DB::statement(<<<'SQL'
            ALTER TABLE `links`
              ADD COLUMN `port_low` BIGINT AS (LEAST(`port_a_id`, `port_b_id`)) STORED,
              ADD COLUMN `port_high` BIGINT AS (GREATEST(`port_a_id`, `port_b_id`)) STORED;
        SQL
        );

        // Add unique index on normalized columns
        DB::statement('CREATE UNIQUE INDEX `links_port_pair_unique` ON `links` (`port_low`, `port_high`)');

        // Add check constraint to prevent linking a port to itself
        // (MySQL/MariaDB constraint syntax - if target DB doesn't support CHECK, it will be ignored by MariaDB older versions)
        DB::statement('ALTER TABLE `links` ADD CONSTRAINT `links_ports_not_equal` CHECK (`port_a_id` <> `port_b_id`)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop constraints and generated columns
        DB::statement('ALTER TABLE `links` DROP INDEX `links_port_pair_unique`');

        // It's safe to try dropping constraint; if platform doesn't support CHECK, it may fail and must be handled by DB admin
        try {
            DB::statement('ALTER TABLE `links` DROP CHECK `links_ports_not_equal`');
        } catch (\Throwable $e) {
            // ignore if not supported
        }

        // Drop generated columns
        try {
            DB::statement('ALTER TABLE `links` DROP COLUMN `port_low`, DROP COLUMN `port_high`');
        } catch (\Throwable $e) {
            // ignore errors on older DBs
        }
    }
};
