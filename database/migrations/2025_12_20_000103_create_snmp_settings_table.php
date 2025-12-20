<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snmp_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Device::class)->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(161);
            $table->string('version', 8)->default('v2c');
            $table->string('community')->nullable();
            $table->unsignedInteger('timeout')->default(3000);
            $table->unsignedTinyInteger('retries')->default(1);
            $table->json('port_name_priority')->nullable();
            $table->json('custom_oids')->nullable();
            $table->timestamps();

            $table->unique('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snmp_settings');
    }
};

