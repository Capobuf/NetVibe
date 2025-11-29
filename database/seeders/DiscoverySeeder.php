<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DiscoverySeeder extends Seeder
{
    public function run(): void
    {
        \App\Models\ScanAgent::firstOrCreate(
            ['name' => 'Local Worker'],
            [
                'description' => 'Built-in worker running on the app server',
                'active' => true,
                'ip_address' => '127.0.0.1',
                'last_seen' => now(),
            ]
        );
    }
}
