<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['server_url', 'api_key', 'checked_at', 'check_status', 'field_mapping'])]
#[Hidden(['api_key'])]
class SnipeItConnection extends Model
{
    public const DEFAULT_MAPPING = [
        'ip' => 'label:IP Address [eth]', 'mac' => 'label:MAC Address [eth]',
        'name' => 'name', 'description' => null,
    ];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'checked_at' => 'datetime', 'field_mapping' => 'array'];
    }

    public function mapping(): array
    {
        return $this->field_mapping ?? self::DEFAULT_MAPPING;
    }

    public function summary(): array
    {
        return [...$this->only('id', 'server_url', 'checked_at', 'check_status'), 'has_key' => true];
    }
}
