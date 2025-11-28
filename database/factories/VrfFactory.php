<?php

namespace Database\Factories;

use App\Models\Vrf;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class VrfFactory extends Factory
{
    protected $model = Vrf::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => $this->faker->word(),
            'rd' => $this->faker->optional()->regexify('[0-9]{1,6}:[0-9]{1,6}'),
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}
