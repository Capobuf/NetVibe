<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credential_profiles')) {
            Schema::create('credential_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable(false);
                $table->string('type')->default('ssh'); // ssh/snmp/http etc
                $table->text('description')->nullable();

                // Credentials storage: username, password (text) and arbitrary json credentials
                $table->string('username')->nullable();
                $table->text('auth_password')->nullable();
                $table->text('auth_credentials')->nullable();

                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_profiles');
    }
};
