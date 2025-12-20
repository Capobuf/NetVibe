<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scan_agents')) {
            return;
        }

        // Add missing columns safely
        Schema::table('scan_agents', function (Blueprint $table) {
            if (! Schema::hasColumn('scan_agents', 'last_seen')) {
                $table->timestamp('last_seen')->nullable()->after('last_access');
            }

            if (! Schema::hasColumn('scan_agents', 'ip_address')) {
                $table->string('ip_address')->nullable()->after('description');
            }

            if (! Schema::hasColumn('scan_agents', 'description')) {
                $table->text('description')->nullable()->after('name');
            }

            if (! Schema::hasColumn('scan_agents', 'active')) {
                $table->boolean('active')->default(true)->after('ip_address');
            }

            if (! Schema::hasColumn('scan_agents', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('id');
            }
        });

        // Migrate values from last_access to last_seen, then drop last_access if present
        if (Schema::hasColumn('scan_agents', 'last_access')) {
            DB::statement('UPDATE scan_agents SET last_seen = last_access WHERE last_access IS NOT NULL');

            Schema::table('scan_agents', function (Blueprint $table) {
                if (Schema::hasColumn('scan_agents', 'last_access')) {
                    $table->dropColumn('last_access');
                }
            });
        }

        // Remove legacy columns if present
        Schema::table('scan_agents', function (Blueprint $table) {
            if (Schema::hasColumn('scan_agents', 'type')) {
                $table->dropColumn('type');
            }

            if (Schema::hasColumn('scan_agents', 'code')) {
                $table->dropColumn('code');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scan_agents')) {
            return;
        }

        // Recreate last_access from last_seen where appropriate
        Schema::table('scan_agents', function (Blueprint $table) {
            if (! Schema::hasColumn('scan_agents', 'last_access')) {
                $table->timestamp('last_access')->nullable()->after('last_seen');
            }
        });

        if (Schema::hasColumn('scan_agents', 'last_seen')) {
            DB::statement('UPDATE scan_agents SET last_access = last_seen WHERE last_seen IS NOT NULL');

            Schema::table('scan_agents', function (Blueprint $table) {
                if (Schema::hasColumn('scan_agents', 'last_seen')) {
                    $table->dropColumn('last_seen');
                }
            });
        }

        // Drop columns we added
        Schema::table('scan_agents', function (Blueprint $table) {
            if (Schema::hasColumn('scan_agents', 'ip_address')) {
                $table->dropColumn('ip_address');
            }

            if (Schema::hasColumn('scan_agents', 'description')) {
                $table->dropColumn('description');
            }

            if (Schema::hasColumn('scan_agents', 'active')) {
                $table->dropColumn('active');
            }

            // We intentionally do not re-create previous columns like 'type' or 'code'
        });
    }
};
