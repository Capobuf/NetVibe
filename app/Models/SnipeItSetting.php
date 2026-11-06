<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope', 'company_id', 'use_global', 'connection_id', 'sync_interval', 'revision'])]
class SnipeItSetting extends Model
{
    /** Allowed automatic sync intervals, in minutes. */
    public const SYNC_INTERVALS = [15, 30, 60, 120, 360, 720, 1440];

    protected $primaryKey = 'scope';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['use_global' => 'boolean', 'sync_interval' => 'integer', 'revision' => 'integer'];
    }

    public static function forCompany(?Company $company): self
    {
        $scope = $company ? 'company:'.$company->id : 'global';

        return static::find($scope) ?? new static([
            'scope' => $scope, 'company_id' => $company?->id, 'use_global' => $company !== null, 'revision' => 0,
        ]);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SnipeItConnection::class);
    }
}
