<?php

namespace App\Observers;

use App\Jobs\IpPrefillJob;
use App\Models\Subnet;

class SubnetObserver
{
    public function created(Subnet $subnet): void
    {
        // Only schedule prefill for reasonably small subnets
        // The IpPrefillService already enforces mask limits, but here we only dispatch when mask >= 22
        if ((int) $subnet->mask >= 22) {
            IpPrefillJob::dispatch($subnet);
        }
    }
}
