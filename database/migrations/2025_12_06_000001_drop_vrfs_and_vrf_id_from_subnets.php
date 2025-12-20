<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove vrf relationship from subnets
        if (Schema::hasTable('subnets') && Schema::hasColumn('subnets', 'vrf_id')) {
            Schema::table('subnets', function (Blueprint $table) {
                try {
                    $table->dropForeign(['vrf_id']);
                } catch (\Throwable $e) {
                    // ignore if foreign not present
                }

                // drop combined index if present
                try {
                    $table->dropIndex(['vrf_id', 'subnet']);
                } catch (\Throwable $_) {
                    // ignore
                }

                try {
                    $table->dropColumn('vrf_id');
                } catch (\Throwable $_) {
                    // ignore
                }
            });
        }

        // Drop vrfs table completely
        if (Schema::hasTable('vrfs')) {
            Schema::dropIfExists('vrfs');
        }
    }

    public function down(): void
    {
        // Recreate vrfs table
        if (! Schema::hasTable('vrfs')) {
            Schema::create('vrfs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('name');
                $table->string('rd')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('customer_id');
                if (Schema::hasTable('customers')) {
                    $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
                }
            });
        }

        // Re-add vrf_id to subnets if table exists and column missing
        if (Schema::hasTable('subnets') && ! Schema::hasColumn('subnets', 'vrf_id')) {
            Schema::table('subnets', function (Blueprint $table) {
                $table->unsignedBigInteger('vrf_id')->nullable()->after('id');
                $table->index(['vrf_id', 'subnet']);
                if (Schema::hasTable('vrfs')) {
                    $table->foreign('vrf_id')->references('id')->on('vrfs')->onDelete('restrict');
                }
            });
        }
    }
};
