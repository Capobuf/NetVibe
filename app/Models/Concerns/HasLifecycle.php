<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\LifecycleStatus;
use Illuminate\Support\Carbon;

/**
 * Shared lifecycle helpers for inventory entities.
 */
trait HasLifecycle
{
    public function markSeen(?Carbon $when = null): void
    {
        $when ??= now();

        $this->lifecycle_status = LifecycleStatus::Active;
        $this->stale_at = null;
        $this->retired_at = null;
    }

    public function markStale(?Carbon $when = null): void
    {
        $when ??= now();

        // Do not downgrade Retired back to Stale
        if (($this->lifecycle_status ?? null) === LifecycleStatus::Retired) {
            return;
        }

        $this->lifecycle_status = LifecycleStatus::Stale;
        $this->stale_at ??= $when;
    }

    public function markRetired(?Carbon $when = null): void
    {
        $when ??= now();

        $this->lifecycle_status = LifecycleStatus::Retired;
        $this->stale_at ??= $when;
        $this->retired_at ??= $when;
    }
}
