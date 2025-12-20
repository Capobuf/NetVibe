<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rimuove l'eventuale colonna legacy section_id dalle subnets e la tabella sections.
        if (Schema::hasTable('subnets') && Schema::hasColumn('subnets', 'section_id')) {
            Schema::table('subnets', function (Blueprint $table): void {
                try {
                    $table->dropForeign(['section_id']);
                } catch (\Throwable $_) {
                    // ignore se FK mancante
                }

                try {
                    $table->dropColumn('section_id');
                } catch (\Throwable $_) {
                    // ignore
                }
            });
        }

        if (Schema::hasTable('sections')) {
            Schema::dropIfExists('sections');
        }
    }

    public function down(): void
    {
        // Non reintroduciamo Sections: il PDL li considera definitivamente rimossi.
    }
};

