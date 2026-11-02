<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cable_label_templates', function (Blueprint $table) {
            $table->dropUnique(['width', 'cable_type']);
            $table->string('name')->default('Profilo salvato');
        });

        $types = ['copper' => 'Rete', 'fiber' => 'Fibra', 'usb' => 'USB', 'power' => 'Alimentazione'];
        foreach (DB::table('cable_label_templates')->get() as $template) {
            $settings = json_decode($template->settings, true, flags: JSON_THROW_ON_ERROR);
            $settings += ['length_mode' => 'auto', 'face_length' => min(45, $settings['max_length']), 'fit' => 'extend'];
            DB::table('cable_label_templates')->where('id', $template->id)->update([
                'name' => $types[$template->cable_type].' — Profilo salvato ('.$template->width.' mm)',
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function down(): void
    {
        // Restoring the old uniqueness would require deleting independently saved profiles.
        throw new RuntimeException('Migrazione non reversibile senza perdita dei profili. Applicare una migrazione correttiva.');
    }
};
