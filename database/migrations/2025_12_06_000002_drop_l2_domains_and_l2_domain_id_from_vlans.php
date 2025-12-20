<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove l2_domain_id from vlans and adjust uniqueness to customer-scoped
        if (Schema::hasTable('vlans') && Schema::hasColumn('vlans', 'l2_domain_id')) {
            Schema::table('vlans', function (Blueprint $table) {
                try {
                    $table->dropForeign(['l2_domain_id']);
                } catch (\Throwable $_) {
                    // ignore
                }

                // drop old unique constraint (l2_domain_id + number)
                try {
                    $table->dropUnique(['l2_domain_id', 'number']);
                } catch (\Throwable $_) {
                    // attempt alternate index name
                    try {
                        $table->dropUnique('vlans_l2_domain_id_number_unique');
                    } catch (\Throwable $_) {
                        // ignore
                    }
                }

                try {
                    $table->dropColumn('l2_domain_id');
                } catch (\Throwable $_) {
                    // ignore
                }

                // enforce uniqueness per customer for vlan numbers
                if (! Schema::hasColumn('vlans', 'customer_id')) {
                    // we'll not add customer_id here — it should already exist in prior migrations
                }

                // create unique index on customer_id + number if not present
                try {
                    $table->unique(['customer_id', 'number']);
                } catch (\Throwable $_) {
                    // ignore if already present or creation fails
                }
            });
        }

        // Drop l2_domains table
        if (Schema::hasTable('l2_domains')) {
            Schema::dropIfExists('l2_domains');
        }
    }

    public function down(): void
    {
        // Recreate l2_domains table
        if (! Schema::hasTable('l2_domains')) {
            Schema::create('l2_domains', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Re-add l2_domain_id to vlans
        if (Schema::hasTable('vlans') && ! Schema::hasColumn('vlans', 'l2_domain_id')) {
            Schema::table('vlans', function (Blueprint $table) {
                $table->unsignedBigInteger('l2_domain_id')->nullable()->after('id');

                if (Schema::hasTable('l2_domains')) {
                    $table->foreign('l2_domain_id')->references('id')->on('l2_domains')->onDelete('set null');
                }

                // restore unique constraint on l2_domain_id + number
                try {
                    $table->unique(['l2_domain_id', 'number']);
                } catch (\Throwable $_) {
                    // ignore
                }

                // remove customer_id,number unique if present
                try {
                    $table->dropUnique(['customer_id', 'number']);
                } catch (\Throwable $_) {
                    try {
                        $table->dropUnique('vlans_customer_id_number_unique');
                    } catch (\Throwable $_) {
                        // ignore
                    }
                }
            });
        }
    }
};
