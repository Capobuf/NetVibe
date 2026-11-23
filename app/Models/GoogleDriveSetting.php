<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/** The Google Cloud project (OAuth client and Picker key) shared by every Drive account. */
#[Fillable(['id', 'client_id', 'client_secret', 'api_key'])]
#[Hidden(['client_secret', 'api_key'])]
class GoogleDriveSetting extends Model
{
    protected function casts(): array
    {
        return ['client_secret' => 'encrypted', 'api_key' => 'encrypted'];
    }

    public static function current(): ?self
    {
        return static::find(1);
    }
}
