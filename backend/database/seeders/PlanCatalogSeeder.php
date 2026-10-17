<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([
            ['slug' => 'basico', 'name' => 'Básico', 'limits' => ['users' => 5, 'clients' => 50, 'monitorings' => null]],
            ['slug' => 'profissional', 'name' => 'Profissional', 'limits' => ['users' => 20, 'clients' => 500, 'monitorings' => null]],
            ['slug' => 'empresarial', 'name' => 'Empresarial', 'limits' => ['users' => null, 'clients' => null, 'monitorings' => null]],
        ] as $defaults) {
            Plan::firstOrCreate(['slug' => $defaults['slug']], $defaults);
        }
    }
}
