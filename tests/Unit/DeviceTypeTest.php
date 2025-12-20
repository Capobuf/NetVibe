<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\DeviceType;
use PHPUnit\Framework\TestCase;

class DeviceTypeTest extends TestCase
{
    public function test_get_color_mappings(): void
    {
        $this->assertSame('warning', DeviceType::Router->getColor());
        $this->assertSame('info', DeviceType::Switch->getColor());
        $this->assertSame('danger', DeviceType::Firewall->getColor());
        $this->assertSame('success', DeviceType::Server->getColor());
        $this->assertSame('gray', DeviceType::Other->getColor());
        $this->assertSame('gray', DeviceType::PDU->getColor());
    }
}
