<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_agents', function (Blueprint $table) {
            $table->string('log_level')->default('ERROR')->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('scan_agents', function (Blueprint $table) {
            $table->dropColumn('log_level');
        });
    }
};
