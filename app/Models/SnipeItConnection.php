<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['server_url', 'api_key', 'checked_at', 'check_status'])]
#[Hidden(['api_key'])]
class SnipeItConnection extends Model
{
    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'checked_at' => 'datetime'];
    }

    public function summary(): array
    {
        return [...$this->only('id', 'server_url', 'checked_at', 'check_status'), 'has_key' => true];
    }
}
