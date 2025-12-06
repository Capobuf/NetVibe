<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     * If the model has a static $enabled property and it's false, the scope is not applied.
     * If session('tenant_id') exists and is not null, we filter by customer_id.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Allow model to opt-out programmatically via a static property
        $modelClass = get_class($model);
        $enabled = $modelClass::$enabled ?? true;

        if ($enabled === false) {
            return;
        }

        // Only enforce tenant filtering when a tenant is selected in session
        $tenantId = session('tenant_id', null);

        if ($tenantId !== null) {
            // Assume models have a customer_id column
            $builder->where($model->getTable() . '.customer_id', $tenantId);
        }
    }
}
