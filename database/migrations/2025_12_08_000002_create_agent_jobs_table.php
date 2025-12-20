<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_jobs')) {
            Schema::create('agent_jobs', function (Blueprint $table) {
                // Use UUID as primary key
                $table->uuid('id')->primary();

                // Relation: device and scan agent (nullable when job not tied to device)
                $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
                $table->foreignId('scan_agent_id')->nullable()->constrained('scan_agents')->nullOnDelete();

                $table->string('type')->nullable(false);

                $table->enum('status', ['pending','running','success','failed','cancelled'])->default('pending');

                // Payload and result data
                $table->json('payload')->nullable();
                $table->longText('output')->nullable();
                $table->json('result')->nullable();

                $table->integer('attempts')->default(0);

                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_jobs');
    }
};
