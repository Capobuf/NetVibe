<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ScanAgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \Illuminate\Support\Facades\DB::table('scan_agents')->insert([
            'id' => 1,
            'name' => 'Localhost',
            'description' => 'Scanning from local machine',
            'type' => 'direct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
