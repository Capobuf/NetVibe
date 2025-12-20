<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scan_agents') && ! Schema::hasColumn('scan_agents', 'deleted_at')) {
            Schema::table('scan_agents', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scan_agents') && Schema::hasColumn('scan_agents', 'deleted_at')) {
            Schema::table('scan_agents', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
