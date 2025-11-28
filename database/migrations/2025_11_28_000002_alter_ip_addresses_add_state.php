<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ip_addresses') && ! Schema::hasColumn('ip_addresses', 'state')) {
            Schema::table('ip_addresses', function (Blueprint $table) {
                $table->integer('state')->default(0);
                $table->index('state');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ip_addresses') && Schema::hasColumn('ip_addresses', 'state')) {
            Schema::table('ip_addresses', function (Blueprint $table) {
                $table->dropIndex(['state']);
                $table->dropColumn('state');
            });
        }
    }
};
