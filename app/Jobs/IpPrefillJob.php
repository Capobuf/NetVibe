<?php

namespace App\Jobs;

use App\Models\Subnet;
use App\Services\IpPrefillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IpPrefillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Subnet $subnet;

    public function __construct(Subnet $subnet)
    {
        $this->subnet = $subnet;
    }

    public function handle(IpPrefillService $service): void
    {
        // Run prefill using service — this will skip IPv6 and large networks per service logic
        $service->prefill($this->subnet);
    }
}
