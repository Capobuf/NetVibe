<?php

declare(strict_types=1);

namespace App\Tenancy;

use RuntimeException;

/**
 * Holds the current tenant context for the lifetime of a request/job.
 *
 * IMPORTANT: Queue workers and long-running processes must clear the store
 * between jobs/requests.
 */
final class TenantContextStore
{
    private ?TenantContext $context = null;

    public function set(TenantContext $context): void
    {
        $this->context = $context;
    }

    public function clear(): void
    {
        $this->context = null;
    }

    public function get(): ?TenantContext
    {
        return $this->context;
    }

    public function currentCustomerId(): ?int
    {
        return $this->context?->customerId;
    }

    public function currentLocationId(): ?int
    {
        return $this->context?->locationId;
    }

    /**
     * @throws RuntimeException
     */
    public function requireCustomerId(): int
    {
        $id = $this->currentCustomerId();

        if ($id === null) {
            throw new RuntimeException('Missing tenant/customer context.');
        }

        return $id;
    }
}
