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
        Schema::table('mikrotik_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('device_interface_id')->nullable()->unique();
            $table->foreign(['device_interface_id', 'device_id'], 'mikrotik_interface_device_fk')
                ->references(['id', 'device_id'])->on('device_interfaces')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mikrotik_sources', function (Blueprint $table) {
            $table->dropForeign('mikrotik_interface_device_fk');
            $table->dropColumn('device_interface_id');
        });
    }
};
