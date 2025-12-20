<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle status for inventory objects.
 *
 * This is distinct from business state (e.g. IpAddress::state).
 * Lifecycle tracks whether an object is currently being observed by discovery.
 */
enum LifecycleStatus: int
{
    case Active = 0;
    case Stale = 1;
    case Retired = 2;

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Stale => 'Stale',
            self::Retired => 'Retired',
        };
    }
}
