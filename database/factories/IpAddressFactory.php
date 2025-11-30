<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IpAddress;
use App\Models\Subnet;
use App\Enums\IpAddressType;
use Illuminate\Database\Eloquent\Factories\Factory;

class IpAddressFactory extends Factory
{
    protected $model = IpAddress::class;

    public function definition(): array
    {
        return [
            // Generate a random IPv4 decimal in the 192.168.1.1 - 192.168.2.254 range
            'ip_addr' => (string) $this->faker->numberBetween(3232235777, 3232236030),
            // Use the enum instance as default — Eloquent casting will handle conversion
            'type' => IpAddressType::Host,
            'description' => $this->faker->sentence(),
            'hostname' => $this->faker->domainName(),
            'mac_address' => $this->faker->macAddress(),
            'state' => 2,
            // Create an associated subnet if none provided
            'subnet_id' => Subnet::factory(),
        ];
    }
}
