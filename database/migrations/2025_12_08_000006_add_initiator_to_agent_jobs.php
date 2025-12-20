<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_jobs') && ! Schema::hasColumn('agent_jobs', 'initiated_by_user_id')) {
            Schema::table('agent_jobs', function (Blueprint $table) {
                $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('scan_agent_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_jobs') && Schema::hasColumn('agent_jobs', 'initiated_by_user_id')) {
            Schema::table('agent_jobs', function (Blueprint $table) {
                $table->dropForeign(['initiated_by_user_id']);
                $table->dropColumn('initiated_by_user_id');
            });
        }
    }
};
