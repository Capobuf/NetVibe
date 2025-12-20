<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Immutable tenant context.
 *
 * In this project "tenant" == Customer.
 * Location is optional and can be used to scope discovery results when available.
 */
final readonly class TenantContext
{
    public function __construct(
        public int $customerId,
        public ?int $locationId = null,
        public string $actorType = 'unknown',
        public ?string $actorId = null,
    ) {
    }
}
