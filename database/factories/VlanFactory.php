<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Vlan;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class VlanFactory extends Factory
{
    protected $model = Vlan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word() . '-VLAN',
            'number' => $this->faker->numberBetween(1, 4094),
            'description' => $this->faker->sentence(),
            'customer_id' => Customer::factory(),
        ];
    }
}
