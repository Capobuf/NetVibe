<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->integer('scan_interval')->nullable()->comment('Scan interval in minutes (null=default, 0=disabled)');
            $table->timestamp('last_scan_at')->nullable();
            $table->string('last_scan_status')->nullable()->comment('success, warning, error, running');
            $table->text('last_scan_output')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->dropColumn([
                'scan_interval',
                'last_scan_at',
                'last_scan_status',
                'last_scan_output'
            ]);
        });
    }
};
