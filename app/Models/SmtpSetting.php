<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'host', 'port', 'security', 'username', 'password', 'from_address', 'from_name'])]
#[Hidden(['password'])]
class SmtpSetting extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
        ];
    }
}
