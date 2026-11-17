<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('subnet_table_separators')->default(false)->change();
        });
        // The old default was stored for everyone, so existing users move to the new default too.
        DB::table('users')->update(['subnet_table_separators' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('subnet_table_separators')->default(true)->change();
        });
    }
};
