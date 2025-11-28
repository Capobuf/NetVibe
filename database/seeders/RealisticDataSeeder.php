<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\Section;
use App\Models\Subnet;
use App\Models\IpAddress;
use App\Services\IpConversionService;

class RealisticDataSeeder extends Seeder
{
    public function run(): void
    {
        $ipService = app(IpConversionService::class);

        // 1. Customers
        $acme = Customer::create(['title' => 'Acme Corp', 'address' => '123 Main St']);
        $contoso = Customer::create(['title' => 'Contoso', 'address' => '456 Tech Blvd']);

        // 2. Sections
        $dcSection = Section::create(['name' => 'Datacenter', 'description' => 'Server Farm', 'master_section_id' => 0, 'permissions' => '{"3":"1","2":"2"}', 'strict_mode' => 1]);
        $wifiSection = Section::create(['name' => 'Office WiFi', 'description' => 'Employee WiFi', 'master_section_id' => 0, 'permissions' => '{"3":"1","2":"2"}', 'strict_mode' => 1]);
        $mgmtSection = Section::create(['name' => 'Management', 'description' => 'OOB Management', 'master_section_id' => 0, 'permissions' => '{"3":"1","2":"2"}', 'strict_mode' => 1]);

        // 3. Subnets
        $subnets = [
            [
                'subnet' => '10.0.10.0',
                'mask' => 24,
                'section_id' => $dcSection->id,
                'customer_id' => $acme->id,
                'description' => 'Production Servers',
                'scan_agent_id' => 1,
            ],
            [
                'subnet' => '192.168.1.0',
                'mask' => 24,
                'section_id' => $wifiSection->id,
                'customer_id' => $contoso->id,
                'description' => 'Floor 1 WiFi',
                'scan_agent_id' => 1,
            ],
             [
                'subnet' => '172.16.0.0',
                'mask' => 24, 
                'section_id' => $mgmtSection->id,
                'customer_id' => $acme->id,
                'description' => 'Switch Mgmt',
                'scan_agent_id' => 1,
            ],
        ];

        foreach ($subnets as $subData) {
            // Convert subnet to decimal
            $decimalSubnet = $ipService->toDecimal($subData['subnet']);
            
            $subnet = Subnet::create(array_merge($subData, [
                'subnet' => $decimalSubnet,
                'is_folder' => 0,
                'is_full' => 0
            ]));

            // 4. Populate IPs
            // Generate first 20 IPs
            // Base IP parts
            $parts = explode('.', $subData['subnet']);
            $base = "{$parts[0]}.{$parts[1]}.{$parts[2]}";

            for ($i = 1; $i <= 20; $i++) {
                $ipStr = "{$base}.{$i}";
                $decimalIp = $ipService->toDecimal($ipStr);
                
                // Random state: 1=Offline, 2=Used, 3=Reserved, 4=DHCP
                $state = rand(1, 4); 
                // Bias towards "Used" (2)
                if (rand(1, 10) > 3) $state = 2;

                IpAddress::create([
                    'subnet_id' => $subnet->id,
                    'ip_addr' => $decimalIp,
                    'description' => "Host-{$i}",
                    'hostname' => "host-{$i}.local",
                    'state' => $state,
                    'customer_id' => $subData['customer_id'],
                ]);
            }
        }
    }
}
