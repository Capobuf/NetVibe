<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use Closure;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait TenantAware
{
    /**
     * When true (default) the tenant scope is applied. Can be toggled to temporarily disable.
     * @var bool
     */
    public static bool $enabled = true;

    /**
     * Boot the trait and register the global tenant scope.
     */
    public static function bootTenantAware(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    /**
     * Temporarily disable the tenant scope during execution of the callback.
     * Useful for background jobs and migrations that need to operate cross-tenant.
     *
     * @param Closure $callback
     * @return mixed
     */
    public static function withoutTenantScope(Closure $callback)
    {
        $modelClass = static::class;
        $previous = $modelClass::$enabled ?? true;

        // disable
        $modelClass::$enabled = false;

        try {
            return $callback();
        } finally {
            // restore
            $modelClass::$enabled = $previous;
        }
    }

    /**
     * Common relation a tenant-scoped model will have.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }
}
