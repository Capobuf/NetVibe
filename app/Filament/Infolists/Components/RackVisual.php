<?php

declare(strict_types=1);

namespace App\Filament\Infolists\Components;

use App\Models\Device;
use App\Models\Rack;
use Filament\Infolists\Components\Entry;

class RackVisual extends Entry
{
    protected string $view = 'filament.infolists.components.rack-visual';

    public function getState(): mixed
    {
        $rack = $this->getRecord();

        return [
            'rack' => $rack,
            'units' => self::buildUnits($rack),
        ];
    }

    /**
     * Build a representation of 42 units for the given Rack.
     *
     * Keys are unit numbers (42 down to 1). Values:
     * - ['type' => 'empty']
     * - ['type' => 'device', 'device' => Device, 'span' => int]
     * - ['type' => 'spanned'] for units that are part of a multi-U device but not the starting (bottom) unit
     */
    public static function buildUnits(Rack $rack): array
    {
        $devices = Device::where('rack_id', $rack->id)
            ->with('deviceModel')
            ->get();

        // initialize occupied map for units 1..42
        $occupied = [];
        for ($i = 1; $i <= 42; $i++) {
            $occupied[$i] = null;
        }

        foreach ($devices as $device) {
            $pos = (int) ($device->rack_position ?? 0);
            if ($pos <= 0 || $pos > 42) {
                continue; // invalid position or not set
            }

            $size = (int) ($device->deviceModel->u_size ?? 1);
            for ($offset = 0; $offset < $size; $offset++) {
                $unit = $pos + $offset; // positions grow upwards
                if ($unit > 42) {
                    break; // out of range
                }

                $occupied[$unit] = [
                    'device' => $device,
                    'start' => ($offset === 0),
                    'span' => $size,
                ];
            }
        }

        $units = [];
        for ($unit = 42; $unit >= 1; $unit--) {
            $slot = $occupied[$unit] ?? null;
            if ($slot === null) {
                $units[$unit] = ['type' => 'empty'];
                continue;
            }

            if ($slot['start']) {
                $units[$unit] = [
                    'type' => 'device',
                    'device' => $slot['device'],
                    'span' => $slot['span'],
                ];
            } else {
                // part of a spanned device, do not render a separate block
                $units[$unit] = ['type' => 'spanned'];
            }
        }

        return $units;
    }
}
