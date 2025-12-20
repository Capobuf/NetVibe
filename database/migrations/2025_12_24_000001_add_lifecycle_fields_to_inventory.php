<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ip_addresses', 'devices', 'ports'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table): void {
                if (! Schema::hasColumn($table, 'lifecycle_status')) {
                    $t->unsignedTinyInteger('lifecycle_status')->default(0)->index();
                }
                if (! Schema::hasColumn($table, 'stale_at')) {
                    $t->timestamp('stale_at')->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'retired_at')) {
                    $t->timestamp('retired_at')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['ip_addresses', 'devices', 'ports'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table): void {
                // Drop indexes implicitly with columns (MySQL) or explicitly where needed.
                foreach (['retired_at', 'stale_at', 'lifecycle_status'] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
