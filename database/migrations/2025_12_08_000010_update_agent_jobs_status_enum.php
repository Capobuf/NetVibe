<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change enum values to [pending, picked_up, completed, failed, timeout]
        // Use raw statement for enum modification compatibility.
        DB::statement("ALTER TABLE agent_jobs MODIFY status ENUM('pending','picked_up','completed','failed','timeout') DEFAULT 'pending';");
    }

    public function down(): void
    {
        // Revert to original enum: pending, running, success, failed, cancelled
        DB::statement("ALTER TABLE agent_jobs MODIFY status ENUM('pending','running','success','failed','cancelled') DEFAULT 'pending';");
    }
};
