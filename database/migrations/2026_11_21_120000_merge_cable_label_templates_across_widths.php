<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A profile now prints on every tape: its width is only the reference its text sizes were designed on.
        // Copies that differ just by tape and text size collapse into the one designed on the narrowest tape.
        $sort = function (array &$value) use (&$sort): void {
            ksort($value);
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
        };
        $kept = [];
        foreach (DB::table('cable_label_templates')->orderBy('width')->orderBy('id')->get() as $template) {
            $name = preg_replace('/\s*\('.$template->width.' mm\)$/u', '', $template->name) ?: $template->name;
            $settings = json_decode($template->settings, true, flags: JSON_THROW_ON_ERROR);
            foreach (['front', 'back'] as $face) {
                foreach ($settings[$face] as &$row) {
                    foreach ($row['blocks'] as &$block) {
                        unset($block['size']);
                    }
                    unset($block);
                }
                unset($row);
            }
            $sort($settings);
            $key = json_encode([$template->cable_type, $name, $settings], JSON_THROW_ON_ERROR);
            if (isset($kept[$key])) {
                DB::table('cable_label_templates')->where('id', $template->id)->delete();

                continue;
            }
            $kept[$key] = true;
            if ($name !== $template->name) {
                DB::table('cable_label_templates')->where('id', $template->id)->update(['name' => $name]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Migrazione non reversibile: i profili duplicati per altezza del nastro sono stati eliminati.');
    }
};
