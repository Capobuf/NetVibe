<?php

namespace Tests\Unit;

use App\Support\Ipv4Cidr;
use PHPUnit\Framework\TestCase;

class Ipv4CidrTest extends TestCase
{
    public function test_normalization_preserves_unsigned_ipv4_boundaries(): void
    {
        $this->assertSame([
            'cidr' => '192.168.1.0/24',
            'network_address' => 3232235776,
            'broadcast_address' => 3232236031,
        ], Ipv4Cidr::parse('192.168.1.129/24'));
        $this->assertSame([
            'cidr' => '0.0.0.0/0',
            'network_address' => 0,
            'broadcast_address' => 4294967295,
        ], Ipv4Cidr::parse('192.168.1.1/0'));
        $this->assertSame([
            'cidr' => '255.255.255.254/31',
            'network_address' => 4294967294,
            'broadcast_address' => 4294967295,
        ], Ipv4Cidr::parse('255.255.255.255/31'));
        $this->assertSame([
            'cidr' => '255.255.255.255/32',
            'network_address' => 4294967295,
            'broadcast_address' => 4294967295,
        ], Ipv4Cidr::parse('255.255.255.255/32'));
    }

    public function test_abbreviated_addresses_and_invalid_prefixes_are_rejected(): void
    {
        foreach (['127.1/24', '192.168.01.1/24', '256.1.1.1/24', '10.0.0.1/33', '10.0.0.1/-1', '10.0.0.1/2.4', '10.0.0.1/024', '10.0.0.1'] as $cidr) {
            $this->assertNull(Ipv4Cidr::parse($cidr), $cidr);
        }
    }
}
