<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('devices')->whereNotNull('type')->exists()) {
            throw new RuntimeException('Sono presenti tipi sui dispositivi: occorre concordarne il trasferimento ai modelli prima di proseguire.');
        }
        Schema::create('model_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::table('device_models', function (Blueprint $table) {
            $table->foreignId('model_type_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('type'));
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->string('type', 30)->nullable());
        Schema::table('device_models', fn (Blueprint $table) => $table->dropConstrainedForeignId('model_type_id'));
        Schema::dropIfExists('model_types');
    }
};
