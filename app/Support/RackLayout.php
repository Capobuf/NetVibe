<?php

namespace App\Support;

use App\Models\Device;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RackLayout
{
    /** @param Collection<int, Device> $devices */
    public static function validate(array $items, int $height, Collection $devices): void
    {
        $byKey = collect($items)->keyBy('key');
        $seen = [];
        $boxes = [];
        foreach ($items as $item) {
            $device = $item['device_id'] ? $devices->get($item['device_id']) : null;
            $label = $device?->name ?? ($item['kind'] === 'shelf' ? 'Ripiano' : 'Cassetto');
            if ($item['kind'] === 'device') {
                if (! $device || isset($seen[$device->id])) {
                    self::fail('Scegli dispositivi distinti appartenenti a questa sede.');
                }
                $seen[$device->id] = true;
                $rackHeight = $device->model?->rack_height_u;
                if ($rackHeight !== null && ! $device->model->without_rack_ears) {
                    if ($item['shelf_key'] !== null || (float) $item['height'] !== (float) $rackHeight) {
                        self::fail("«{$label}» deve essere montato direttamente e occupare {$rackHeight}U.");
                    }
                } elseif ($item['shelf_key'] === null) {
                    self::fail("Scegli un appoggio per «{$label}».");
                }
            } elseif ($item['device_id'] !== null || $item['shelf_key'] !== null || floor($item['height']) !== (float) $item['height']) {
                self::fail('Ripiani e cassetti richiedono unità intere e non possono contenere associazioni a dispositivi.');
            }

            // Physical positions are measured from the bottom, independently of U labels.
            $bottom = $item['position'];
            if ($item['shelf_key'] !== null) {
                $current = $item;
                $visited = [$item['key'] => true];
                $supports = [];
                while ($current['shelf_key'] !== null) {
                    $support = $byKey->get($current['shelf_key']);
                    if (! $support || isset($visited[$support['key']]) || ! in_array($support['kind'], ['shelf', 'device'], true)
                        || $current['position'] !== $support['position']
                        || $support['x'] >= $current['x'] + $current['width'] || $current['x'] >= $support['x'] + $support['width']) {
                        self::fail("L’appoggio di «{$label}» non è valido. Scegli un ripiano o un dispositivo sottostante.");
                    }
                    $visited[$support['key']] = true;
                    $supports[] = $support;
                    $current = $support;
                }
                $bottom = array_reduce(array_reverse($supports), fn ($bottom, $support) => $bottom + ($support['kind'] === 'shelf' ? 0 : $support['height']), $current['position']);
            } elseif ((float) $item['width'] !== 100.0 || (float) $item['x'] !== 0.0) {
                self::fail('Gli elementi montati direttamente devono usare tutta la larghezza del rack.');
            }
            $box = ['kind' => $item['kind'], 'bottom' => $bottom, 'top' => $bottom + ($item['kind'] === 'shelf' ? 0 : $item['height']), 'left' => $item['x'], 'right' => $item['x'] + $item['width'], 'label' => $label];
            if ($box['top'] > $height || $box['right'] > 100 || ($item['kind'] === 'shelf' && $height < $item['position'] + 1)) {
                self::fail("«{$label}» supera il perimetro del rack. Spostalo o ridimensionalo prima di salvare.");
            }
            foreach ($boxes as $other) {
                if (($box['kind'] === 'shelf' && $other['kind'] === 'shelf' && $box['bottom'] === $other['bottom'])
                    || ($box['bottom'] < $other['top'] && $box['top'] > $other['bottom'] && $box['left'] < $other['right'] && $box['right'] > $other['left'])) {
                    self::fail("«{$label}» si sovrappone a «{$other['label']}».");
                }
            }
            $boxes[] = $box;
        }
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }
}
