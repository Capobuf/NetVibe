<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scan_agents')) {
            Schema::create('scan_agents', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('type')->default('direct');
                $table->string('code')->nullable();
                $table->timestamp('last_access')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->timestamps();

                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
                $table->index('customer_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scan_agents')) {
            Schema::dropIfExists('scan_agents');
        }
    }
};
