<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\IpConversionService;

class IpRoundTripTest extends TestCase
{
    public function test_ipv4_roundtrip_to_and_from_decimal(): void
    {
        $service = new IpConversionService();

        $ip = '192.168.1.1';

        // Convert to decimal
        $decimal = $service->toDecimal($ip);

        $this->assertIsString($decimal);
        $this->assertEquals('3232235777', $decimal);

        // Convert back to address object and ensure string is identical
        $addr = $service->fromDecimal($decimal);
        $this->assertNotNull($addr);
        $this->assertEquals($ip, $addr->toString());
    }
}
