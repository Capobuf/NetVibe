<?php

use App\Models\Customer;
use App\Models\IpAddress;
use App\Models\Subnet;
use Illuminate\Validation\ValidationException;

test('ip address customer_id must match subnet customer_id', function () {
    $customerA = Customer::factory()->create();
    $customerB = Customer::factory()->create();

    $subnet = Subnet::factory()->create([
        'customer_id' => $customerA->id,
        'cidr' => '172.16.0.0/24',
        'discover_subnet' => true,
    ]);

    expect(fn () => IpAddress::create([
        'customer_id' => $customerB->id,
        'subnet_id' => $subnet->id,
        'ip_addr' => '2886729729', // 172.16.0.1
        'mac_address' => '00:11:22:33:44:55',
    ]))->toThrow(ValidationException::class);
});
