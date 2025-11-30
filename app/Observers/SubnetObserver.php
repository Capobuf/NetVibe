<?php

namespace App\Observers;

// IpPrefillJob removed - subnet prefill deprecated (sparse storage)
use App\Models\Subnet;

class SubnetObserver
{
    public function created(Subnet $subnet): void
    {
        // Prefill behavior is deprecated and no scheduling is performed.
        // Previously we dispatched IpPrefillJob for smaller subnets.
        // Prefill is deprecated — subnets are now stored sparsely on demand.
    }
}
