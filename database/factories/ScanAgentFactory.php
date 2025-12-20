<?php

namespace Database\Factories;

use App\Models\ScanAgent;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScanAgentFactory extends Factory
{
    protected $model = ScanAgent::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'description' => $this->faker->optional()->sentence(),
            'ip_address' => $this->faker->optional()->ipv4(),
            'active' => true,
            'last_seen' => now(),
            // NetVibe uses 1 ScanAgent per customer.
            'customer_id' => Customer::factory(),
        ];
    }
}
