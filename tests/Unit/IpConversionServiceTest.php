<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\IpConversionService;

class IpConversionServiceTest extends TestCase
{
    public function test_from_decimal_converts_ipv4_correctly(): void
    {
        $service = new IpConversionService();

        // 192.168.1.1 => 3232235777
        $address = $service->fromDecimal('3232235777');

        $this->assertNotNull($address);
        $this->assertEquals('192.168.1.1', $address->toString());
    }

    public function test_from_decimal_zero_returns_null(): void
    {
        $service = new IpConversionService();

        $this->assertNull($service->fromDecimal(0));
        $this->assertNull($service->fromDecimal('0'));
    }

    public function test_from_decimal_large_ipv6_like_returns_null(): void
    {
        $service = new IpConversionService();

        // A number larger than IPv4 range should return null (IPv6 not supported yet)
        $large = '340282366920938463463374607431768211455'; // 2^128-1

        $this->assertNull($service->fromDecimal($large));
    }
}
