<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \Illuminate\Support\Facades\DB::table('sections')->insert([
            [
                'name' => 'Customers',
                'description' => 'Customer networks',
                'permissions' => '{"3":"1","2":"2"}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'IPv6',
                'description' => 'IPv6 address space',
                'permissions' => '{"3":"1","2":"2"}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
