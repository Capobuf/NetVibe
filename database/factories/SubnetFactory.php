<?php

namespace Database\Factories;

use App\Models\Subnet;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubnetFactory extends Factory
{
    protected $model = Subnet::class;

    public function definition(): array
    {
        $ipService = app(\App\Services\IpConversionService::class);

        // pick a random base IPv4 address and use mask 24 by default
        $ip = $this->faker->ipv4();
        $mask = 24;

        return [
            // tenant ownership moved to customer
            'customer_id' => Customer::factory(),
            'subnet' => $ipService->toDecimal($ip),
            'mask' => $mask,
            // 'cidr' intentionally omitted to avoid invoking the typed setCidrAttribute on null
            'name' => $this->faker->optional()->word(),
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}
