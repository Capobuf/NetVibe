<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeviceModel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'brand',
        'model',
        'type',
        'u_size',
        'ethernet_ports',
        'sfp_ports',
        'description',
    ];

    protected $casts = [
        'type' => DeviceType::class,
        'u_size' => 'integer',
        'ethernet_ports' => 'integer',
        'sfp_ports' => 'integer',
    ];

    public function devices()
    {
        return $this->hasMany(Device::class, 'device_model_id');
    }

    // Friendly combined name for Filament relationship labels and selects
    public function getNameAttribute(): string
    {
        return trim(sprintf('%s %s', $this->brand ?? '', $this->model ?? ''));
    }

    /**
     * Create a DeviceModel from quick-create data submitted by Filament's createOptionForm.
     *
     * This method is defensive: Filament may submit the enum "type" as either the enum backed
     * value (e.g. 'switch') or the human label (e.g. 'Switch'). Normalise to a backed value
     * which Eloquent will cast correctly.
     *
     * Returns the newly created model's primary key.
     */
    public static function createFromQuickCreate(array $data): int
    {
        if (isset($data['type'])) {
            $typeValue = $data['type'];

            // If Filament / Eloquent already provided an enum instance, normalise to its backed value
            if ($typeValue instanceof DeviceType) {
                $data['type'] = $typeValue->value;
            } else {
                // Try interpreting as a backed value first
                $enum = DeviceType::tryFrom((string) $typeValue);

                if ($enum) {
                    $data['type'] = $enum->value;
                } else {
                    // Try matching by label (case-insensitive) against the enum cases
                    foreach (DeviceType::cases() as $case) {
                        if (strcasecmp($case->getLabel() ?? '', (string) $typeValue) === 0) {
                            $data['type'] = $case->value;
                            break;
                        }
                    }

                    // If still missing/invalid, default to 'other' to avoid validation/casting errors
                    if (empty($data['type'])) {
                        $data['type'] = DeviceType::Other->value;
                    }
                }
            }
        }

        $model = self::create($data);
        return $model->getKey();
    }
}
