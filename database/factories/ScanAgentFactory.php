<?php

namespace Database\Factories;

use App\Models\ScanAgent;
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
            'customer_id' => null,
        ];
    }
}
