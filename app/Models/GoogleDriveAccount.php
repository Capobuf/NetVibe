<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['email', 'refresh_token'])]
#[Hidden(['refresh_token'])]
class GoogleDriveAccount extends Model
{
    protected function casts(): array
    {
        return ['refresh_token' => 'encrypted'];
    }

    public function syncs(): HasMany
    {
        return $this->hasMany(GoogleDriveSync::class, 'account_id');
    }
}
