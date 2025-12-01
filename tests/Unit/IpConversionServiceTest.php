<?php

use App\Models\Subnet;
use App\Models\IpAddress;
use App\Services\IpConversionService;

it('calculates the next free ip correctly in an empty subnet', function () {
    $subnet = Subnet::factory()->create(['subnet' => '3232235776', 'mask' => 24, 'cidr' => '192.168.1.0/24']);

    $next = app(IpConversionService::class)->getNextFreeIp($subnet);

    expect($next)->toBe('192.168.1.1');
});

it('skips occupied ips to find the next free one', function () {
    $subnet = Subnet::factory()->create(['subnet' => '3232235776', 'mask' => 24, 'cidr' => '192.168.1.0/24']);
    IpAddress::factory()->create(['subnet_id' => $subnet->id, 'ip_addr' => '3232235777']); // .1

    $next = app(IpConversionService::class)->getNextFreeIp($subnet);

    expect($next)->toBe('192.168.1.2');
});

it('treats soft deleted ips as free (recycling)', function () {
    $subnet = Subnet::factory()->create(['subnet' => '3232235776', 'mask' => 24, 'cidr' => '192.168.1.0/24']);
    $ip = IpAddress::factory()->create(['subnet_id' => $subnet->id, 'ip_addr' => '3232235777']); // .1
    $ip->delete(); // Soft Delete

    $next = app(IpConversionService::class)->getNextFreeIp($subnet);

    expect($next)->toBe('192.168.1.1');
});

it('returns null for ipv6 subnets', function () {
    $subnet = Subnet::factory()->create(['cidr' => '2001:db8::/64', 'subnet' => '0', 'mask' => 64]);
    $next = app(IpConversionService::class)->getNextFreeIp($subnet);
    expect($next)->toBeNull();
});
