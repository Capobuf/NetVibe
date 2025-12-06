<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Link extends Model
{
    protected $fillable = [
        'port_a_id',
        'port_b_id',
        'cable_type_id',
        'length',
        'color',
    ];

    public function portA()
    {
        return $this->belongsTo(Port::class, 'port_a_id');
    }

    public function portB()
    {
        return $this->belongsTo(Port::class, 'port_b_id');
    }

    public function cableType()
    {
        return $this->belongsTo(CableType::class, 'cable_type_id');
    }

    protected static function booted(): void
    {
        static::creating(function (Link $link) {
            self::validateNoSelfOrDuplicate($link);
        });

        static::updating(function (Link $link) {
            self::validateNoSelfOrDuplicate($link, excludeId: $link->id);
        });
    }

    /**
     * Centralized validation to avoid duplicating checks in both creating/updating hooks.
     * If excludeId is provided, that ID will be ignored when checking for existing links.
     */
    protected static function validateNoSelfOrDuplicate(Link $link, ?int $excludeId = null): void
    {
        if ($link->port_a_id === $link->port_b_id) {
            throw new \RuntimeException('Cannot create a link to the same port');
        }

        $query = self::where(function ($q) use ($link) {
            $q->where('port_a_id', $link->port_a_id)->where('port_b_id', $link->port_b_id);
        })->orWhere(function ($q) use ($link) {
            $q->where('port_a_id', $link->port_b_id)->where('port_b_id', $link->port_a_id);
        });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new \RuntimeException('A link already exists between these ports');
        }
    }
}
